<?php

namespace App\Services\Sales;

use App\Models\Sale;

/**
 * Legacy sales keep LightStores qty/UOM on sale_items — never expose Centrix product UOM.
 */
class LegacySalePresentation
{
    /**
     * @return array<string, mixed>
     */
    public static function saleItemEagerLoad(): array
    {
        return [
            'items' => fn ($query) => $query->with([
                'product' => fn ($product) => $product->select('product_code', 'product_name'),
            ]),
        ];
    }

    public static function stripCentrixUnitData(Sale $sale): void
    {
        if (! $sale->isLegacyImport()) {
            return;
        }

        foreach ($sale->items as $item) {
            if (! $item->relationLoaded('product') || ! $item->product) {
                continue;
            }

            $item->product->unsetRelation('unit');
            $item->product->makeHidden([
                'unit_id',
                'uom',
                'unit_price',
                'retail_package_setting',
                'retail_package',
            ]);
        }
    }
}
