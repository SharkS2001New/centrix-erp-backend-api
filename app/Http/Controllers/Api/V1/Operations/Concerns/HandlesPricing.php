<?php

namespace App\Http\Controllers\Api\V1\Operations\Concerns;

use App\Models\Product;
use App\Models\RetailPackageSetting;
use App\Models\RouteModel;
use App\Support\RetailPricing;

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

        if ($isRetailLine && $rps) {
            $price = RetailPricing::linePrice($product, $rps, $quantity, true);
        } else {
            $price = $base * $quantity;
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
