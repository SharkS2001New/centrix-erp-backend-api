<?php

namespace App\Services\Inventory;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Stock quantities in the ledger and current_stock are always base (smallest) units.
 * Cost prices (last_cost_price, receipt cost_price) are per purchase/package unit.
 *
 * Valuation: (base_qty / conversion_factor) × unit_cost
 */
class StockCostCalculation
{
    public static function normalizedConversionFactor(mixed $factor): float
    {
        $value = (float) ($factor ?? 1);

        return $value > 0 ? $value : 1.0;
    }

    public static function convertedQuantity(float $baseQuantity, mixed $conversionFactor): float
    {
        return $baseQuantity / self::normalizedConversionFactor($conversionFactor);
    }

    public static function lineCostFromBaseQuantity(float $baseQuantity, float $unitCostPerPackage, mixed $conversionFactor): float
    {
        return round(
            self::convertedQuantity($baseQuantity, $conversionFactor) * max(0, $unitCostPerPackage),
            2,
        );
    }

    public static function conversionFactorForOrganizationProduct(int $organizationId, string $productCode): float
    {
        $factor = DB::table('products as p')
            ->join('uoms as u', 'u.id', '=', 'p.unit_id')
            ->where('p.organization_id', $organizationId)
            ->where('p.product_code', $productCode)
            ->whereNull('p.deleted_at')
            ->value('u.conversion_factor');

        return self::normalizedConversionFactor($factor);
    }

    public static function conversionFactorForProduct(?Product $product): float
    {
        if (! $product) {
            return 1.0;
        }

        $product->loadMissing('unit');

        return self::normalizedConversionFactor($product->unit?->conversion_factor);
    }

    public static function conversionFactorSqlExpression(string $uomAlias = 'u'): string
    {
        return "GREATEST(COALESCE({$uomAlias}.conversion_factor, 1), 1)";
    }

    public static function convertedQuantitySqlExpression(string $quantityExpression, string $uomAlias = 'u'): string
    {
        $factor = self::conversionFactorSqlExpression($uomAlias);

        return "(({$quantityExpression}) / {$factor})";
    }

    public static function costValueSqlExpression(string $quantityExpression, string $unitCostExpression, string $uomAlias = 'u'): string
    {
        $converted = self::convertedQuantitySqlExpression($quantityExpression, $uomAlias);

        return "({$converted} * ({$unitCostExpression}))";
    }
}
