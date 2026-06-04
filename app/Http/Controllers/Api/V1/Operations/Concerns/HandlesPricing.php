<?php

namespace App\Http\Controllers\Api\V1\Operations\Concerns;

use App\Models\Product;
use App\Models\RetailPackageSetting;
use App\Models\RouteModel;

trait HandlesPricing
{
    protected function lineUnitPrice(
        Product $product,
        float $quantity,
        bool $isRetailLine,
        ?int $routeId = null
    ): float {
        $base = (float) $product->unit_price;
        $rps = RetailPackageSetting::where('product_code', $product->product_code)->first();
        $conversion = (float) ($product->unit?->conversion_factor ?? 1);
        if ($conversion <= 0) {
            $conversion = 1;
        }

        if ($isRetailLine && $rps && $rps->max_qty_measure > 0) {
            $perUnit = ($base / (float) $rps->max_qty_measure) + (float) $rps->markup_price;
            $price = $perUnit * $quantity;
        } else {
            $markup = (float) ($rps->wholesale_markup_price ?? 0);
            $price = ($base + $markup) * $quantity;
        }

        if ($routeId) {
            $route = RouteModel::find($routeId);
            if ($route) {
                $price += (float) $route->route_markup_price * $quantity;
            }
        }

        return round($price, 2);
    }

    protected function isRetailLine(Product $product, bool $onWholesaleRetailFlag): bool
    {
        return (bool) $product->sell_on_retail && $onWholesaleRetailFlag;
    }
}
