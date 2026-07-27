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
        ?int $organizationId = null,
    ): float {
        if ($baseQty <= 0) {
            return 0.0;
        }

        $product->loadMissing('unit');
        $rps = RetailPackageSetting::where('product_code', $product->product_code)->first();
        $baseUnitPrice = (float) $product->unit_price;
        $conversion = max(1.0, (float) ($product->unit?->conversion_factor ?? 1));
        $middleFactor = $this->resolvedMiddleFactor($product->unit?->middle_factor, $conversion);
        $tiers = $this->tiersForRetailPackage($rps);
        $packQty = $conversion > 1 ? $baseQty / $conversion : $baseQty;

        if ($isRetailLine && $rps && $tiers !== []) {
            $lineAmount = $this->linePrice($baseUnitPrice, $tiers, $baseQty, true, $conversion, $middleFactor);
        } elseif ($tiers !== []) {
            $wholesaleTiers = $this->tiersWithPriceMode($tiers, 'wholesale');
            $tier = $this->tierForQuantity($wholesaleTiers, $baseQty);
            if ($tier) {
                $lineAmount = $this->linePriceForTier(
                    $baseUnitPrice,
                    $tier,
                    $baseQty,
                    $conversion,
                    $middleFactor,
                    scaleMarkup: false,
                );
            } else {
                $wholesaleMarkup = (float) ($rps->wholesale_markup_price ?? 0);
                $lineAmount = round($packQty * $baseUnitPrice + $wholesaleMarkup, 2);
            }
        } else {
            $wholesaleMarkup = (float) ($rps->wholesale_markup_price ?? 0);
            $lineAmount = round($packQty * $baseUnitPrice + $wholesaleMarkup, 2);
        }

        return round($this->applyRouteMarkup(
            $lineAmount,
            $baseQty,
            $packQty,
            $isRetailLine,
            $routeId,
            $organizationId,
        ), 2);
    }

    /** @return array{0: float, 1: float} gross unit price per sold display unit, line amount (after discount) */
    public function resolveLineAmounts(
        Product $product,
        float $baseQty,
        bool $isRetailLine,
        float $discountGiven,
        ?int $routeId,
        ?float $clientUnitPricePerBase,
        bool $trustClientUnitPrice,
        ?int $organizationId = null,
    ): array {
        $product->loadMissing('unit');
        $factor = max(1.0, (float) ($product->unit?->conversion_factor ?? 1));
        $entryQty = $factor > 1 && ! $isRetailLine ? $baseQty / $factor : $baseQty;

        if ($trustClientUnitPrice && $clientUnitPricePerBase !== null && $clientUnitPricePerBase > 0) {
            $amount = round($clientUnitPricePerBase * $baseQty, 2);
            $unitPrice = $entryQty > 0 ? round($amount / $entryQty, 4) : $clientUnitPricePerBase;

            return [$unitPrice, $amount];
        }

        $beforeDiscount = $this->lineTotalBeforeDiscount($product, $baseQty, $isRetailLine, $routeId, $organizationId);
        $amount = round(max(0, $beforeDiscount - max(0, $discountGiven)), 2);
        $unitPrice = $entryQty > 0 ? round($beforeDiscount / $entryQty, 4) : 0.0;

        return [$unitPrice, $amount];
    }

    /** @return list<array{min_qty: float, max_qty: ?float, measure_level: string, price_mode: string, markup_price: float}> */
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
                    'price_mode' => $this->normalizeTierPriceMode($tier),
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
                'price_mode' => 'retail',
                'markup_price' => (float) ($rps->markup_price ?? 0),
            ];
        }
        if ((float) ($rps->wholesale_qty_measure ?? 0) > 0) {
            $tiers[] = [
                'min_qty' => (float) ($rps->max_qty_measure ?? 0) + 0.001,
                'max_qty' => (float) $rps->wholesale_qty_measure,
                'measure_level' => 'middle',
                'price_mode' => 'wholesale',
                'markup_price' => (float) ($rps->wholesale_markup_price ?? 0),
            ];
        }

        return $tiers;
    }

    /** @param  array<string, mixed>  $tier */
    protected function normalizeTierPriceMode(array $tier): string
    {
        $raw = strtolower((string) ($tier['price_mode'] ?? $tier['pricing_mode'] ?? 'retail'));

        return $raw === 'wholesale' ? 'wholesale' : 'retail';
    }

    /**
     * @param  list<array{min_qty: float, max_qty: ?float, measure_level: string, price_mode: string, markup_price: float}>  $tiers
     * @return list<array{min_qty: float, max_qty: ?float, measure_level: string, price_mode: string, markup_price: float}>
     */
    protected function tiersWithPriceMode(array $tiers, string $priceMode): array
    {
        $mode = $this->normalizeTierPriceMode(['price_mode' => $priceMode]);

        return array_values(array_filter(
            $tiers,
            fn (array $tier) => $this->normalizeTierPriceMode($tier) === $mode,
        ));
    }

    /** @param  list<array{min_qty: float, max_qty: ?float, measure_level: string, price_mode: string, markup_price: float}>  $tiers */
    protected function tierForQuantity(array $tiers, float $quantity, bool $extendPastMax = false): ?array
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

        if (! $extendPastMax || $tiers === []) {
            return null;
        }

        $sorted = $tiers;
        usort($sorted, fn ($a, $b) => $a['min_qty'] <=> $b['min_qty']);
        for ($i = count($sorted) - 1; $i >= 0; $i--) {
            if ($quantity + 0.0001 >= $sorted[$i]['min_qty']) {
                return $sorted[$i];
            }
        }

        return null;
    }

    protected function resolvedMiddleFactor(mixed $middleFactor, float $conversion): float
    {
        $mid = (float) ($middleFactor ?? 0);
        if ($mid > 1) {
            return $mid;
        }
        if ($conversion >= 2) {
            return $conversion / 2;
        }

        return 1.0;
    }

    protected function wholesalePricePerSmallUnit(float $baseUnitPrice, float $conversion): float
    {
        return $conversion <= 1 ? $baseUnitPrice : $baseUnitPrice / $conversion;
    }

    protected function wholesalePriceAtMeasureLevel(
        float $baseUnitPrice,
        float $conversion,
        float $middleFactor,
        string $level,
    ): float {
        if ($conversion <= 1) {
            return $baseUnitPrice;
        }
        if ($level === 'full') {
            return $baseUnitPrice;
        }
        if ($level === 'middle') {
            return ($baseUnitPrice / $conversion) * $middleFactor;
        }

        return $baseUnitPrice / $conversion;
    }

    protected function smallUnitsPerLevel(float $conversion, float $middleFactor, string $level): float
    {
        if ($level === 'full' && $conversion > 1) {
            return $conversion;
        }
        if ($level === 'middle') {
            return max(1.0, $middleFactor);
        }

        return 1.0;
    }

    /**
     * @param  array{min_qty: float, max_qty: ?float, measure_level: string, price_mode: string, markup_price: float}  $tier
     */
    protected function retailMarkupApplications(
        float $qty,
        array $tier,
        float $conversion,
        float $middleFactor,
    ): float {
        if ($qty <= 0) {
            return 0.0;
        }

        $level = (string) ($tier['measure_level'] ?? 'small');
        $unitSize = $this->smallUnitsPerLevel($conversion, $middleFactor, $level);

        if ($this->normalizeTierPriceMode($tier) === 'wholesale' && $level === 'full' && $conversion > 1) {
            $unitSize = max(1.0, $middleFactor);
        }

        if ($unitSize <= 0) {
            $unitSize = 1.0;
        }

        return $qty / $unitSize;
    }

    /** @param  array{min_qty: float, max_qty: ?float, measure_level: string, price_mode: string, markup_price: float}  $tier */
    protected function linePriceForTier(
        float $baseUnitPrice,
        array $tier,
        float $qty,
        float $conversion,
        float $middleFactor,
        bool $scaleMarkup = false,
    ): float {
        $markup = (float) $tier['markup_price'];
        $stableBase = $this->wholesalePricePerSmallUnit($baseUnitPrice, $conversion) * $qty;
        $mode = $this->normalizeTierPriceMode($tier);

        if ($mode === 'wholesale') {
            $apps = $scaleMarkup
                ? $this->retailMarkupApplications($qty, $tier, $conversion, $middleFactor)
                : 1.0;

            return round($stableBase + ($markup * $apps), 2);
        }

        $wholesaleBase = $this->wholesalePriceAtMeasureLevel(
            $baseUnitPrice,
            $conversion,
            $middleFactor,
            $tier['measure_level'],
        );
        $smallPerLevel = $this->smallUnitsPerLevel($conversion, $middleFactor, $tier['measure_level']);
        $priceAtLevel = $wholesaleBase + $markup;
        $perSmall = $priceAtLevel / max(1.0, $smallPerLevel);

        return round($perSmall * $qty, 2);
    }

    /** @param  list<array{min_qty: float, max_qty: ?float, measure_level: string, price_mode: string, markup_price: float}>  $tiers */
    protected function linePrice(
        float $baseUnitPrice,
        array $tiers,
        float $qty,
        bool $isRetail,
        float $conversion,
        float $middleFactor,
    ): float {
        if ($tiers === []) {
            $perSmall = $this->wholesalePricePerSmallUnit($baseUnitPrice, $conversion);

            return round($perSmall * $qty, 2);
        }

        $applicableTiers = $isRetail ? $tiers : $this->tiersWithPriceMode($tiers, 'wholesale');
        $tier = $this->tierForQuantity($applicableTiers, $qty, $isRetail);
        if (! $tier) {
            $perSmall = $this->wholesalePricePerSmallUnit($baseUnitPrice, $conversion);

            return round($perSmall * $qty, 2);
        }

        return $this->linePriceForTier(
            $baseUnitPrice,
            $tier,
            $qty,
            $conversion,
            $middleFactor,
            scaleMarkup: $isRetail,
        );
    }

    protected function applyRouteMarkup(
        float $lineAmount,
        float $baseQty,
        float $packQty,
        bool $isRetailLine,
        ?int $routeId,
        ?int $organizationId = null,
    ): float {
        if (! $routeId) {
            return $lineAmount;
        }

        $routeQuery = RouteModel::query()->where('id', $routeId);
        if ($organizationId !== null) {
            $routeQuery->where('organization_id', $organizationId);
        }
        $route = $routeQuery->first();
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
