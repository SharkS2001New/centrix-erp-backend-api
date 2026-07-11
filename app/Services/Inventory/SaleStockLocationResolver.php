<?php

namespace App\Services\Inventory;

use App\Models\Product;

class SaleStockLocationResolver
{
    public static function stockRouteAsRetail(
        Product $product,
        bool $onWholesaleRetailFlag,
        array $salesSettings,
    ): bool {
        return (bool) $product->sell_on_retail && $onWholesaleRetailFlag;
    }

    /**
     * Resolve shop vs store for a sale line using org stock-source settings.
     */
    public static function forLine(
        string $channel,
        array $inventorySettings,
        array $salesSettings,
        Product $product,
        bool $onWholesaleRetailFlag,
    ): string {
        $stockAsRetail = self::stockRouteAsRetail($product, $onWholesaleRetailFlag, $salesSettings);

        return self::forStockRouteFlag($channel, $inventorySettings, $salesSettings, $stockAsRetail);
    }

    /**
     * Default list/catalog location when line retail flag is unknown (wholesale).
     */
    public static function forCatalogList(
        string $channel,
        array $inventorySettings,
        array $salesSettings,
    ): string {
        return self::forStockRouteFlag($channel, $inventorySettings, $salesSettings, false);
    }

    public static function forRouteFlag(
        string $channel,
        array $inventorySettings,
        array $salesSettings,
        bool $stockAsRetail,
    ): string {
        return self::forStockRouteFlag($channel, $inventorySettings, $salesSettings, $stockAsRetail);
    }

    protected static function forStockRouteFlag(
        string $channel,
        array $inventorySettings,
        array $salesSettings,
        bool $stockAsRetail,
    ): string {
        if (! empty($salesSettings['retail_shop_wholesale_store_stock'])) {
            return $stockAsRetail ? 'shop' : 'store';
        }

        if (! empty($salesSettings['allow_sell_from_shop']) && empty($salesSettings['allow_sell_from_store'])) {
            return 'shop';
        }

        if (empty($salesSettings['allow_sell_from_shop']) && ! empty($salesSettings['allow_sell_from_store'])) {
            return 'store';
        }

        return self::defaultChannelLocation($channel, $inventorySettings);
    }

    public static function defaultChannelLocation(string $channel, array $inventorySettings): string
    {
        return match ($channel) {
            'backend', 'mobile', 'whatsapp' => $inventorySettings['default_distribution_sale_location'] ?? 'store',
            default => $inventorySettings['default_pos_sale_location'] ?? 'shop',
        };
    }
}
