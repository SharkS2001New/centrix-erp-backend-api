<?php

namespace App\Http\Controllers\Api\V1\Operations\Concerns;

use App\Models\Product;
use App\Services\Sales\PosLinePricingService;

trait HandlesPricing
{
    protected function lineUnitPrice(
        Product $product,
        float $quantity,
        bool $isRetailLine,
        ?int $routeId = null
    ): float {
        return app(PosLinePricingService::class)->lineTotalBeforeDiscount(
            $product,
            $quantity,
            $isRetailLine,
            $routeId,
        );
    }

    protected function isRetailLine(Product $product, bool $onWholesaleRetailFlag): bool
    {
        return (bool) $product->sell_on_retail && $onWholesaleRetailFlag;
    }
}
