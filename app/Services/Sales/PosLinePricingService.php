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

        $formulas = PricingFormulaSettings::forOrganizationId($organizationId);
        $product->loadMissing('unit');
        $rps = RetailPackageSetting::where('product_code', $product->product_code)->first();
        $baseUnitPrice = (float) $product->unit_price;
        $conversion = max(1.0, (float) ($product->unit?->conversion_factor ?? 1));
        $middleFactor = $this->resolvedMiddleFactor($product->unit?->middle_factor, $conversion);
        $tiers = $this->tiersForRetailPackage($rps, $conversion);
        $packQty = $conversion > 1 ? $baseQty / $conversion : $baseQty;

        if ($isRetailLine && $rps && $tiers !== []) {
            $lineAmount = $this->linePrice($baseUnitPrice, $tiers, $baseQty, true, $conversion, $middleFactor, $formulas);
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
                    formulas: $formulas,
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
            $formulas,
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

        // Retail lines always price from retail_package_settings (aggregate wholesale
        // + tier markup). Never trust client unit×qty — that folds markup into the
        // unit or drops the package add-on (e.g. 125×25=3125 instead of 3125+30).
        if (
            ! $isRetailLine
            && $trustClientUnitPrice
            && $clientUnitPricePerBase !== null
            && $clientUnitPricePerBase > 0
        ) {
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
    protected function tiersForRetailPackage(?RetailPackageSetting $rps, float $conversion = 1.0): array
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

        $legacyRetailMax = (float) ($rps->max_qty_measure ?? 0);
        $legacyLooksLikePackMarkup = $conversion > 1.0 && $legacyRetailMax > max(1.0, $conversion / 2.0);
        $tiers = [];
        if ($legacyRetailMax > 0) {
            $tiers[] = [
                'min_qty' => 1.0,
                'max_qty' => $legacyRetailMax,
                'measure_level' => $legacyLooksLikePackMarkup ? 'full' : 'small',
                'price_mode' => $legacyLooksLikePackMarkup ? 'wholesale' : 'retail',
                'markup_price' => (float) ($rps->markup_price ?? 0),
            ];
        }
        if ((float) ($rps->wholesale_qty_measure ?? 0) > 0) {
            $tiers[] = [
                'min_qty' => (float) ($rps->max_qty_measure ?? 0) + 0.001,
                'max_qty' => (float) $rps->wholesale_qty_measure,
                'measure_level' => $legacyLooksLikePackMarkup ? 'middle' : 'small',
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

    /**
     * @param  list<array{min_qty: float, max_qty: ?float, measure_level: string, price_mode: string, markup_price: float}>  $tiers
     */
    protected function isPerUnitRetailTier(array $tier): bool
    {
        return $this->normalizeTierPriceMode($tier) === 'retail'
            && (string) ($tier['measure_level'] ?? 'small') === 'small';
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

        // Qty sits in a gap between capped bands (e.g. 12.2 when tiers are 1–12 and
        // 12.5–49) — use the next band so first-tier /kg markup does not leak upward.
        for ($i = 0; $i < count($sorted) - 1; $i++) {
            $prevMax = $sorted[$i]['max_qty'];
            $next = $sorted[$i + 1];
            if (
                $prevMax !== null
                && $quantity > $prevMax + 0.0001
                && $quantity + 0.0001 < $next['min_qty']
            ) {
                return $next;
            }
        }

        for ($i = count($sorted) - 1; $i >= 0; $i--) {
            if ($quantity + 0.0001 < $sorted[$i]['min_qty']) {
                continue;
            }
            $tier = $sorted[$i];
            // Past a capped per-kg retail band: do not keep applying /kg markup
            // (e.g. 1–44 @ +2.777 must not price 45kg at the same 95/kg rate).
            if (
                $tier['max_qty'] !== null
                && $quantity > $tier['max_qty'] + 0.0001
                && $this->isPerUnitRetailTier($tier)
            ) {
                return null;
            }

            return $tier;
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

    protected function retailMarkupChunkSize(
        array $tier,
        float $conversion,
        float $middleFactor,
    ): float {
        $level = (string) ($tier['measure_level'] ?? 'small');
        $mode = $this->normalizeTierPriceMode($tier);

        // Per-kg / per-piece retail tiers (match web retail-pricing.js).
        if ($mode === 'retail' && $level === 'small') {
            return 1.0;
        }

        // Pack products: accumulate markup per half pack (25kg of a 50kg bag).
        if ($conversion >= 2) {
            return $conversion / 2;
        }

        return max(1.0, $this->smallUnitsPerLevel($conversion, $middleFactor, $level));
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

        $chunk = $this->retailMarkupChunkSize($tier, $conversion, $middleFactor);
        if ($chunk <= 1.0) {
            return $qty;
        }

        return (float) ceil($qty / $chunk - 1e-9);
    }

    /** @param  array{min_qty: float, max_qty: ?float, measure_level: string, price_mode: string, markup_price: float}  $tier */
    /** @param  array<string, string>|null  $formulas */
    protected function linePriceForTier(
        float $baseUnitPrice,
        array $tier,
        float $qty,
        float $conversion,
        float $middleFactor,
        bool $scaleMarkup = false,
        ?array $formulas = null,
    ): float {
        $markup = (float) $tier['markup_price'];
        $perSmall = $this->wholesalePricePerSmallUnit($baseUnitPrice, $conversion);
        $stableBase = $perSmall * $qty;
        $mode = $this->normalizeTierPriceMode($tier);
        $packQty = $conversion > 1 ? $qty / $conversion : $qty;
        $formulas ??= PricingFormulaSettings::defaults();

        // Aggregate wholesale for qty, then add package markup (never fold markup into unit).
        if ($mode === 'wholesale' && ! $scaleMarkup) {
            $fallback = round($stableBase + $markup, 2);
            $vars = PricingFormulaSettings::lineVars(
                $stableBase,
                $markup,
                1.0,
                $qty,
                $packQty,
                $conversion,
                $perSmall,
                $middleFactor,
                $baseUnitPrice,
            );

            return PricingFormulaEvaluator::evaluate(
                $formulas[PricingFormulaSettings::WHOLESALE_LINE] ?? PricingFormulaSettings::defaults()[PricingFormulaSettings::WHOLESALE_LINE],
                $vars,
                $fallback,
            );
        }

        $apps = $this->retailMarkupApplications($qty, $tier, $conversion, $middleFactor);
        $fallback = round($stableBase + ($markup * $apps), 2);
        $vars = PricingFormulaSettings::lineVars(
            $stableBase,
            $markup,
            $apps,
            $qty,
            $packQty,
            $conversion,
            $perSmall,
            $middleFactor,
            $baseUnitPrice,
        );

        return PricingFormulaEvaluator::evaluate(
            $formulas[PricingFormulaSettings::RETAIL_LINE] ?? PricingFormulaSettings::defaults()[PricingFormulaSettings::RETAIL_LINE],
            $vars,
            $fallback,
        );
    }

    /** @param  list<array{min_qty: float, max_qty: ?float, measure_level: string, price_mode: string, markup_price: float}>  $tiers */
    /** @param  array<string, string>|null  $formulas */
    protected function linePrice(
        float $baseUnitPrice,
        array $tiers,
        float $qty,
        bool $isRetail,
        float $conversion,
        float $middleFactor,
        ?array $formulas = null,
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
            formulas: $formulas,
        );
    }

    /** @param  array<string, string>|null  $formulas */
    protected function applyRouteMarkup(
        float $lineAmount,
        float $baseQty,
        float $packQty,
        bool $isRetailLine,
        ?int $routeId,
        ?int $organizationId = null,
        ?array $formulas = null,
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

        $formulas ??= PricingFormulaSettings::forOrganizationId($organizationId);
        $wholesaleQty = max(0.0, $packQty > 0 ? $packQty : $baseQty);
        $vars = PricingFormulaSettings::routeVars($lineAmount, $routeMarkup, $wholesaleQty, $baseQty);

        if ($isRetailLine) {
            $fallback = $lineAmount + $routeMarkup;

            return PricingFormulaEvaluator::evaluate(
                $formulas[PricingFormulaSettings::ROUTE_RETAIL] ?? PricingFormulaSettings::defaults()[PricingFormulaSettings::ROUTE_RETAIL],
                $vars,
                $fallback,
            );
        }

        $fallback = $lineAmount + ($routeMarkup * $wholesaleQty);

        return PricingFormulaEvaluator::evaluate(
            $formulas[PricingFormulaSettings::ROUTE_WHOLESALE] ?? PricingFormulaSettings::defaults()[PricingFormulaSettings::ROUTE_WHOLESALE],
            $vars,
            $fallback,
        );
    }

    /**
     * Preview breakdown for org-admin formula tester.
     *
     * @param  array<string, string>|null  $draftFormulas
     * @return array<string, mixed>
     */
    public function previewLine(
        Product $product,
        float $baseQty,
        bool $isRetailLine,
        ?int $routeId,
        ?int $organizationId,
        ?array $draftFormulas = null,
    ): array {
        $formulas = PricingFormulaSettings::normalize($draftFormulas ?? PricingFormulaSettings::forOrganizationId($organizationId));
        $product->loadMissing('unit');
        $rps = RetailPackageSetting::where('product_code', $product->product_code)->first();
        $baseUnitPrice = (float) $product->unit_price;
        $conversion = max(1.0, (float) ($product->unit?->conversion_factor ?? 1));
        $middleFactor = $this->resolvedMiddleFactor($product->unit?->middle_factor, $conversion);
        $tiers = $this->tiersForRetailPackage($rps, $conversion);
        $packQty = $conversion > 1 ? $baseQty / $conversion : $baseQty;
        $perSmall = $this->wholesalePricePerSmallUnit($baseUnitPrice, $conversion);
        $aggregateWholesale = round($perSmall * $baseQty, 4);

        $tier = null;
        $apps = 0.0;
        $tierMarkup = 0.0;
        if ($tiers !== []) {
            $applicable = $isRetailLine ? $tiers : $this->tiersWithPriceMode($tiers, 'wholesale');
            $tier = $this->tierForQuantity($applicable, $baseQty, $isRetailLine);
            if ($tier) {
                $tierMarkup = (float) $tier['markup_price'];
                $apps = $isRetailLine || $this->normalizeTierPriceMode($tier) === 'retail'
                    ? $this->retailMarkupApplications($baseQty, $tier, $conversion, $middleFactor)
                    : 1.0;
            }
        }

        $lineBeforeRoute = $this->linePrice(
            $baseUnitPrice,
            $tiers,
            $baseQty,
            $isRetailLine,
            $conversion,
            $middleFactor,
            $formulas,
        );
        $lineAfterRoute = $this->applyRouteMarkup(
            $lineBeforeRoute,
            $baseQty,
            $packQty,
            $isRetailLine,
            $routeId,
            $organizationId,
            $formulas,
        );

        $routeMarkup = 0.0;
        if ($routeId) {
            $routeQuery = RouteModel::query()->where('id', $routeId);
            if ($organizationId !== null) {
                $routeQuery->where('organization_id', $organizationId);
            }
            $routeMarkup = max(0.0, (float) ($routeQuery->value('route_markup_price') ?? 0));
        }

        return [
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'base_qty' => $baseQty,
            'is_retail' => $isRetailLine,
            'conversion_factor' => $conversion,
            'per_small' => round($perSmall, 4),
            'aggregate_wholesale' => $aggregateWholesale,
            'tier' => $tier,
            'tiers' => $tiers,
            'applicable_tiers' => $applicable,
            'tier_markup' => $tierMarkup,
            'markup_apps' => $apps,
            'pack_qty' => round($packQty, 4),
            'route_markup' => $routeMarkup,
            'line_before_route' => $lineBeforeRoute,
            'line_total' => $lineAfterRoute,
            'formulas_used' => $formulas,
            'has_retail_package' => (bool) $rps,
        ];
    }
}
