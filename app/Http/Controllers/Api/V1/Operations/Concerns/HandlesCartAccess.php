<?php

namespace App\Http\Controllers\Api\V1\Operations\Concerns;

use App\Models\Product;
use App\Models\TemporaryCart;
use App\Models\User;
use App\Services\Sales\OrderNumberAllocator;
use App\Services\Sales\SaleLineQuantityDisplayService;

trait HandlesCartAccess
{
    use HandlesBranchScope;

    protected function findOwnedCart(int $cartId, User $user, bool $withLines = true): TemporaryCart
    {
        $query = TemporaryCart::query();
        if ($withLines) {
            $query->with('lines');
        }

        $cart = $query->findOrFail($cartId);
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
