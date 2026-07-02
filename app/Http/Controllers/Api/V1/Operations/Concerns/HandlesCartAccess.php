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
                $lineArray['display_unit_price'] = $qtyDisplay->displayUnitPrice(
                    (float) $line->quantity,
                    (float) $line->amount,
                    $product,
                    $isRetail,
                );
            } else {
                $lineArray['qty_disp'] = trim((float) $line->quantity.' '.($line->uom ?? ''));
                $lineArray['display_unit_price'] = round((float) $line->unit_price, 2);
            }

            return $lineArray;
        })->values()->all();

        return $payload;
    }
}
