<?php

namespace App\Services\Sales;

use App\Models\Product;

/**
 * Server-side product discount amounts — mirrors web {@see src/lib/product-discount.js}
 * and mobile {@see lib/utils/product_discount.dart}.
 */
class ProductLineDiscountService
{
    public function productHasConfiguredDiscount(Product $product): bool
    {
        $type = $product->discount_type === 'fixed' ? 'fixed' : 'percentage';

        if ($type === 'fixed') {
            return (float) ($product->discount_value ?? 0) > 0;
        }

        return (float) ($product->discount_percentage ?? 0) > 0;
    }

    public function computeProductLineDiscount(
        Product $product,
        float $lineAmountBeforeDiscount,
        float $packQty = 1,
    ): float {
        if ($lineAmountBeforeDiscount <= 0) {
            return 0.0;
        }

        $type = $product->discount_type === 'fixed' ? 'fixed' : 'percentage';

        if ($type === 'fixed') {
            $perPack = (float) ($product->discount_value ?? 0);
            if ($perPack <= 0) {
                return 0.0;
            }

            return round(max(0, $packQty * $perPack), 2);
        }

        $pct = (float) ($product->discount_percentage ?? 0);
        if ($pct <= 0) {
            return 0.0;
        }

        return round(max(0, $lineAmountBeforeDiscount * ($pct / 100)), 2);
    }
}
