<?php

namespace App\Services\Sales;

use App\Models\Product;
use App\Models\RetailPackageSetting;
use App\Models\RouteModel;

/**
 * Server-side POS / mobile line pricing — mirrors web {@see src/lib/pos-line.js}
 * and mobile {@see lib/utils/pos_line.dart}.
 *
 * Cart line quantity is always in the smallest UOM (base units).
 */
class PosLinePricingService
{
    public function lineTotalBeforeDiscount(
        Product $product,
        float $baseQty,
        bool $isRetailLine,
        ?int $routeId = null,
    ): float {
        if ($baseQty <= 0) {
            return 0.0;
        }

        $product->loadMissing('unit');
        $rps = RetailPackageSetting::where('product_code', $product->product_code)->first();
        $baseUnitPrice = (float) $product->unit_price;
        $conversion = max(1.0, (float) ($product->unit?->conversion_factor ?? 1));
        $tiers = $this->tiersForRetailPackage($rps);

        if ($isRetailLine && $rps && $tiers !== []) {
            $lineAmount = $this->linePrice($baseUnitPrice, $tiers, $baseQty, true, $conversion);
            $packQty = $conversion > 1 ? $baseQty / $conversion : $baseQty;
        } else {
            $wholesaleMarkup = (float) ($rps->wholesale_markup_price ?? 0);
            $packQty = $conversion > 1 ? $baseQty / $conversion : $baseQty;
            $displayUnitPrice = $baseUnitPrice + $wholesaleMarkup;
            $lineAmount = round($packQty * $displayUnitPrice, 2);
        }

        return round($this->applyRouteMarkup(
            $lineAmount,
            $baseQty,
            $packQty ?? ($conversion > 1 ? $baseQty / $conversion : $baseQty),
            $isRetailLine,
            $routeId,
        ), 2);
    }

    /** @return array{0: float, 1: float} unit price per base qty, line amount (after line discount) */
    public function resolveLineAmounts(
        Product $product,
        float $baseQty,
        bool $isRetailLine,
        float $discountGiven,
        ?int $routeId,
        ?float $clientUnitPricePerBase,
        bool $trustClientUnitPrice,
    ): array {
        if ($trustClientUnitPrice && $clientUnitPricePerBase !== null && $clientUnitPricePerBase > 0) {
            $amount = round($clientUnitPricePerBase * $baseQty, 2);

            return [$clientUnitPricePerBase, $amount];
        }

        $beforeDiscount = $this->lineTotalBeforeDiscount($product, $baseQty, $isRetailLine, $routeId);
        $amount = round(max(0, $beforeDiscount - max(0, $discountGiven)), 2);
        $unitPrice = $baseQty > 0 ? round($amount / $baseQty, 4) : 0.0;

        return [$unitPrice, $amount];
    }

    /** @return list<array{min_qty: float, max_qty: ?float, measure_level: string, markup_price: float}> */
    protected function tiersForRetailPackage(?RetailPackageSetting $rps): array
    {
        if (! $rps) {
            return [];
        }

        $raw = $rps->pricing_tiers;
        if (is_array($raw) && $raw !== []) {
            $tiers = [];
            foreach ($raw as $tier) {
                if (! is_array($tier)) {
                    continue;
                }
                $min = (float) ($tier['min_qty'] ?? 0);
                if ($min <= 0) {
                    continue;
                }
                $maxRaw = $tier['max_qty'] ?? null;
                $tiers[] = [
                    'min_qty' => $min,
                    'max_qty' => $maxRaw === null || $maxRaw === '' ? null : (float) $maxRaw,
                    'measure_level' => (string) ($tier['measure_level'] ?? 'small'),
                    'markup_price' => (float) ($tier['markup_price'] ?? 0),
                ];
            }
            usort($tiers, fn ($a, $b) => $a['min_qty'] <=> $b['min_qty']);

            return $tiers;
        }

        $tiers = [];
        if ((float) ($rps->max_qty_measure ?? 0) > 0) {
            $tiers[] = [
                'min_qty' => 1.0,
                'max_qty' => (float) $rps->max_qty_measure,
                'measure_level' => 'small',
                'markup_price' => (float) ($rps->markup_price ?? 0),
            ];
        }
        if ((float) ($rps->wholesale_qty_measure ?? 0) > 0) {
            $tiers[] = [
                'min_qty' => (float) ($rps->max_qty_measure ?? 0) + 0.001,
                'max_qty' => (float) $rps->wholesale_qty_measure,
                'measure_level' => 'middle',
                'markup_price' => (float) ($rps->wholesale_markup_price ?? 0),
            ];
        }

        return $tiers;
    }

    /** @param  list<array{min_qty: float, max_qty: ?float, measure_level: string, markup_price: float}>  $tiers */
    protected function tierForQuantity(array $tiers, float $quantity): ?array
    {
        foreach ($tiers as $tier) {
            if ($quantity + 0.0001 < $tier['min_qty']) {
                continue;
            }
            if ($tier['max_qty'] !== null && $quantity > $tier['max_qty'] + 0.0001) {
                continue;
            }

            return $tier;
        }

        return null;
    }

    protected function wholesalePricePerSmallUnit(float $baseUnitPrice, float $conversion): float
    {
        return $conversion <= 1 ? $baseUnitPrice : $baseUnitPrice / $conversion;
    }

    protected function wholesalePriceAtMeasureLevel(float $baseUnitPrice, float $conversion, string $level): float
    {
        if ($conversion <= 1) {
            return $baseUnitPrice;
        }
        if ($level === 'full') {
            return $baseUnitPrice;
        }
        if ($level === 'middle') {
            return $baseUnitPrice / $conversion;
        }

        return $baseUnitPrice / $conversion;
    }

    /** @param  list<array{min_qty: float, max_qty: ?float, measure_level: string, markup_price: float}>  $tiers */
    protected function retailUnitPrice(float $baseUnitPrice, array $tiers, float $qty, float $conversion): float
    {
        $tier = $this->tierForQuantity($tiers, $qty);
        if (! $tier) {
            return $this->wholesalePricePerSmallUnit($baseUnitPrice, $conversion);
        }

        $level = $tier['measure_level'];
        $priceAtLevel = $this->wholesalePriceAtMeasureLevel($baseUnitPrice, $conversion, $level)
            + (float) $tier['markup_price'];
        $smallPerLevel = $level === 'full' && $conversion > 1 ? $conversion : 1;

        return $priceAtLevel / max(1.0, $smallPerLevel);
    }

    /** @param  list<array{min_qty: float, max_qty: ?float, measure_level: string, markup_price: float}>  $tiers */
    protected function linePrice(
        float $baseUnitPrice,
        array $tiers,
        float $qty,
        bool $isRetail,
        float $conversion,
    ): float {
        if (! $isRetail || $tiers === []) {
            $perSmall = $this->wholesalePricePerSmallUnit($baseUnitPrice, $conversion);

            return round($perSmall * $qty, 2);
        }

        $perUnit = $this->retailUnitPrice($baseUnitPrice, $tiers, $qty, $conversion);

        return round($perUnit * $qty, 2);
    }

    protected function applyRouteMarkup(
        float $lineAmount,
        float $baseQty,
        float $packQty,
        bool $isRetailLine,
        ?int $routeId,
    ): float {
        if (! $routeId) {
            return $lineAmount;
        }

        $route = RouteModel::find($routeId);
        if (! $route) {
            return $lineAmount;
        }

        $routeMarkup = max(0.0, (float) $route->route_markup_price);
        if ($routeMarkup <= 0) {
            return $lineAmount;
        }

        if ($isRetailLine) {
            return $lineAmount + $routeMarkup;
        }

        $wholesaleQty = max(0.0, $packQty > 0 ? $packQty : $baseQty);

        return $lineAmount + ($routeMarkup * $wholesaleQty);
    }
}
