<?php

namespace App\Services\Sales;

class MobileProductListSettings
{
    public const MODE_IN_STOCK_ONLY = 'in_stock_only';

    public const MODE_ALL_PRODUCTS = 'all_products';

    /** @return list<string> */
    public static function modes(): array
    {
        return [
            self::MODE_IN_STOCK_ONLY,
            self::MODE_ALL_PRODUCTS,
        ];
    }

    /** @param  array<string, mixed>  $salesSettings */
    public function mode(array $salesSettings): string
    {
        $mode = (string) ($salesSettings['mobile_product_list_mode'] ?? self::MODE_IN_STOCK_ONLY);

        return in_array($mode, self::modes(), true) ? $mode : self::MODE_IN_STOCK_ONLY;
    }

    /** @param  array<string, mixed>  $salesSettings */
    public function filtersToInStockOnly(array $salesSettings): bool
    {
        return $this->mode($salesSettings) === self::MODE_IN_STOCK_ONLY;
    }
}
