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
        bool $includeNextOrderNum = true,
    ): array {
        $user ??= request()->user();
        $cart->loadMissing('lines');
        $payload = array_merge($cart->toArray(), $extra);

        if ($user && $includeNextOrderNum) {
            $payload['next_order_num'] = app(OrderNumberAllocator::class)
                ->nextForOrganization((int) $user->organization_id);
        }

        $productCodes = $cart->lines->pluck('product_code')->unique()->values()->all();
        $products = Product::query()
            ->with('unit')
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
                $discountGiven = (float) ($line->discount_given ?? 0);
                $storedDisplay = $line->display_unit_price !== null
                    ? (float) $line->display_unit_price
                    : null;
                $lineArray['display_unit_price'] = $qtyDisplay->displayUnitPrice(
                    (float) $line->quantity,
                    (float) $line->amount,
                    $product,
                    $isRetail,
                    $discountGiven,
                    (float) $line->unit_price,
                    $storedDisplay,
                );
                $lineArray['display_discount_per_unit'] = $qtyDisplay->displayDiscountPerUnit(
                    (float) $line->quantity,
                    $discountGiven,
                    $product,
                    $isRetail,
                );
                $lineArray['display_amount'] = $qtyDisplay->displayLineAmount(
                    (float) $line->quantity,
                    (float) $line->amount,
                    $product,
                    $isRetail,
                    $discountGiven,
                    (float) $line->unit_price,
                );
            } else {
                $lineArray['qty_disp'] = trim((float) $line->quantity.' '.($line->uom ?? ''));
                $lineArray['display_unit_price'] = round((float) $line->unit_price, 2);
                $lineArray['display_discount_per_unit'] = 0.0;
                $lineArray['display_amount'] = round((float) $line->amount, 2);
            }

            return $lineArray;
        })->values()->all();

        if ($user) {
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

        return $payload;
    }
}
