<?php

namespace App\Http\Controllers\Api\V1\Operations\Concerns;

use App\Models\Product;
use App\Models\TemporaryCart;
use App\Models\User;
use App\Services\Erp\FloatSessionValidator;
use App\Services\Sales\OrderNumberAllocator;
use App\Services\Sales\PosDailyOrderNumberAllocator;
use App\Services\Sales\SaleLineQuantityDisplayService;

trait HandlesCartAccess
{
    use HandlesBranchScope;

    /**
     * Route params arrive as strings; reject non-numeric ids (e.g. offline "active") cleanly.
     */
    protected function resolveCartId(int|string $cartId): int
    {
        if (is_int($cartId)) {
            if ($cartId <= 0) {
                abort(404, 'Cart not found.');
            }

            return $cartId;
        }

        $raw = trim((string) $cartId);
        if ($raw === '' || ! ctype_digit($raw)) {
            abort(404, 'Cart not found.');
        }

        $id = (int) $raw;
        if ($id <= 0) {
            abort(404, 'Cart not found.');
        }

        return $id;
    }

    protected function findOwnedCart(int|string $cartId, User $user, bool $withLines = true): TemporaryCart
    {
        $cartId = $this->resolveCartId($cartId);
        $query = TemporaryCart::query();
        if ($withLines) {
            $query->with('lines');
        }

        $cart = $query->find($cartId);
        if (! $cart) {
            abort(404, 'Cart not found. It may have already been checked out — reopen the order to continue editing.');
        }
        if ((int) $cart->user_id !== (int) $user->id) {
            abort(403, 'This cart belongs to another cashier.');
        }

        $this->userAccess()->assertBranchAccess(
            $user,
            $cart->branch_id ? (int) $cart->branch_id : null,
            'This cart belongs to another branch.',
        );

        return $cart;
    }

    /** @return array<string, mixed> */
    protected function presentCart(
        TemporaryCart $cart,
        ?User $user = null,
        array $extra = [],
        bool $includeNextOrderNum = false,
    ): array {
        $user ??= request()->user();
        $cart->loadMissing('lines');
        $payload = array_merge($cart->toArray(), $extra);

        if ($user && $user->organization_id) {
            // Always peek — POS UI needs a stable “New Order - S00xx” label after line adds.
            // Peek only (no lock); real allocation still happens at checkout.
            $payload['next_order_num'] = app(OrderNumberAllocator::class)
                ->peekNextForOrganization((int) $user->organization_id);

            $channel = strtolower(trim((string) ($cart->channel ?? '')));
            $source = strtolower(trim((string) ($cart->order_source ?? '')));
            if ($channel === 'pos' || $source === 'pos') {
                // Prefer the cashier's currently open float session so Cash Sales #
                // resets after Z/close even when the reused cart still points at
                // yesterday's (or the just-closed) session id.
                $floatSessionId = null;
                try {
                    $floatSessionId = FloatSessionValidator::forUser($user)
                        ->findOpenSessionIdForUser($user, $cart);
                } catch (\Throwable) {
                    $floatSessionId = null;
                }
                if ($floatSessionId === null && $cart->float_session_id) {
                    $floatSessionId = (int) $cart->float_session_id;
                }
                if (
                    $floatSessionId
                    && (int) ($cart->float_session_id ?? 0) !== $floatSessionId
                ) {
                    $cart->float_session_id = $floatSessionId;
                    $cart->save();
                }
                if ($floatSessionId) {
                    $payload['float_session_id'] = $floatSessionId;
                }
                $posPeek = app(PosDailyOrderNumberAllocator::class)->peekNextForCashier(
                    (int) $user->organization_id,
                    (int) $user->id,
                    null,
                    $floatSessionId,
                );
                if ($posPeek !== null) {
                    $payload['next_pos_order_num'] = $posPeek['pos_order_num'];
                    $payload['next_pos_order_date'] = $posPeek['pos_order_date'];
                }
            }
        }

        $productCodes = $cart->lines->pluck('product_code')->filter()->unique()->values()->all();
        $products = $productCodes === []
            ? collect()
            : Product::query()
                ->with('unit')
                ->when(
                    $user?->organization_id,
                    fn ($q) => $q->where('organization_id', (int) $user->organization_id),
                )
                ->whereIn('product_code', $productCodes)
                ->get()
                ->keyBy('product_code');
        $qtyDisplay = app(SaleLineQuantityDisplayService::class);

        $payload['lines'] = $cart->lines->map(function ($line) use ($products, $qtyDisplay) {
            $lineArray = $line->toArray();
            $product = $products->get($line->product_code);
            $isRetail = (bool) $line->on_wholesale_retail;

            if ($product) {
                $lineArray['qty_disp'] = $qtyDisplay->formatLineQtyDisplay(
                    (float) $line->quantity,
                    $product,
                    $isRetail,
                    $line->uom,
                );
                $lineArray['display_unit_price'] = $qtyDisplay->displayUnitPrice(
                    (float) $line->quantity,
                    (float) $line->amount,
                    $product,
                    $isRetail,
                    (float) ($line->discount_given ?? 0),
                    (float) $line->unit_price,
                );
            } else {
                $lineArray['qty_disp'] = trim((float) $line->quantity.' '.($line->uom ?? ''));
                $lineArray['display_unit_price'] = round((float) $line->unit_price, 2);
            }

            return $lineArray;
        })->values()->all();

        if ($user) {
            $this->presentCartDiscountMeta($cart, $user, $payload);
        }

        return $payload;
    }

    /** @param  array<string, mixed>  $payload */
    protected function presentCartDiscountMeta(TemporaryCart $cart, User $user, array &$payload): void
    {
        $hasLineDiscount = $cart->lines->contains(
            static fn ($line) => (float) ($line->discount_given ?? 0) > 0.01,
        );
        $needsDiscountMeta = $hasLineDiscount
            || (float) ($cart->order_discount ?? 0) > 0.01
            || (int) ($cart->superseded_sale_id ?? 0) > 0;

        if (! $needsDiscountMeta) {
            $payload['discount_approval_pending'] = false;
            $payload['discount_approval_request'] = null;
            $payload['discount_resubmit'] = false;
            $payload['advised_discount_ready'] = false;
            $payload['cart_has_manual_discount'] = false;

            return;
        }

        $discounts = app(\App\Services\Sales\DiscountApprovalService::class);
        $pending = $discounts->pendingRequestForCart($cart, $user);
        $payload['discount_approval_pending'] = $pending !== null;
        $payload['discount_approval_request'] = $discounts->presentPendingRequest($pending);
        $payload['discount_resubmit'] = $discounts->cartResubmitsRejectedDiscountOrder($cart);
        $payload['advised_discount_ready'] = $discounts->cartMatchesAdvisedDiscount($cart);
        $payload['cart_has_manual_discount'] = $discounts->cartHasManualDiscount($cart);
        if ($payload['discount_resubmit'] && (int) ($cart->superseded_sale_id ?? 0) > 0) {
            $superseded = \App\Models\Sale::query()->find((int) $cart->superseded_sale_id);
            if ($superseded !== null) {
                $payload['advised_discount_lines'] = $discounts->saleAdvisedDiscountLines($superseded);
            }
        }
    }
}
