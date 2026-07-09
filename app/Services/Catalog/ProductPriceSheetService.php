<?php

namespace App\Services\Catalog;

/**
 * Builds product price sheet rows — mirrors web {@see src/lib/product-price-sheet.js}.
 */
class ProductPriceSheetService
{
    /** @return array<string, mixed> */
    public function buildRow(
        object $product,
        ?object $uom,
        ?array $retailPackage,
        string $subcategoryName = 'Uncategorized',
        string $categoryName = 'Uncategorized',
        bool $retailPricingEnabled = true,
    ): array {
        $unitPrice = (float) ($product->unit_price ?? 0);
        $costPrice = (float) ($product->last_cost_price ?? 0);
        $hasUnitPrice = $unitPrice > 0;
        $sellOnRetail = $retailPricingEnabled
            && $hasUnitPrice
            && ((int) ($product->sell_on_retail ?? 0) === 1);
        $conversion = max(1.0, (float) ($uom->conversion_factor ?? 1));
        $middleFactor = max(0, (float) ($uom->middle_factor ?? 0));
        $tiers = $this->tiersForRetailPackage($retailPackage);
        $hasTiers = $tiers !== [];

        $retailPrice = $hasUnitPrice
            ? $this->priceForMeasureLevel($unitPrice, $tiers, $conversion, $middleFactor, 'small', $sellOnRetail && $hasTiers, 'retail')
            : null;

        $hasMiddlePack = $middleFactor > 1;
        $dozensPrice = ($hasUnitPrice && $hasMiddlePack)
            ? $this->priceForMeasureLevel($unitPrice, $tiers, $conversion, $middleFactor, 'middle', $sellOnRetail && $hasTiers, 'retail')
            : null;

        $aboveDozensPrice = ($hasUnitPrice && $sellOnRetail && $this->tiersWithPriceMode($tiers, 'wholesale') !== [])
            ? $this->aboveDozensTierPrice($unitPrice, $tiers, $conversion, $middleFactor)
            : null;

        $wholesalePrice = $hasUnitPrice
            ? $this->wholesaleColumnPrice($unitPrice, $tiers, $conversion, $middleFactor)
            : null;

        return [
            'product_code' => $product->product_code,
            'product_name' => $product->product_name ?? $product->product_code,
            'category_name' => $categoryName,
            'subcategory_name' => $subcategoryName,
            'packaging' => $this->packagingLabel($uom, $conversion),
            'unit_cost' => $costPrice > 0 ? round($costPrice, 2) : null,
            'last_cost_price' => $costPrice > 0 ? round($costPrice, 2) : null,
            'sell_on_retail' => $sellOnRetail,
            'has_tiers' => $hasTiers,
            'has_middle_pack' => $hasMiddlePack,
            'retail_price' => $retailPrice !== null ? round($retailPrice, 2) : null,
            'dozens_price' => $dozensPrice !== null ? round($dozensPrice, 2) : null,
            'above_dozens_price' => $aboveDozensPrice !== null ? round($aboveDozensPrice, 2) : null,
            'wholesale_price' => $wholesalePrice !== null ? round($wholesalePrice, 2) : null,
            'wholesale_margin' => $this->marginPercent($wholesalePrice, $costPrice),
            'retail_margin' => $this->marginPercent($retailPrice, $costPrice),
            'unit_price' => round($unitPrice, 2),
        ];
    }

    protected function packagingLabel(?object $uom, float $conversion): string
    {
        $small = trim((string) ($uom->small_packaging_label ?? $uom->uom_type ?? 'pcs'));
        if ($conversion <= 1) {
            return "1 {$small}";
        }

        return '1 x '.((int) $conversion).$small;
    }

    protected function marginPercent(?float $sell, float $cost): ?int
    {
        if ($sell === null || $sell <= 0 || $cost < 0) {
            return null;
        }

        return (int) round((($sell - $cost) / $sell) * 100);
    }

    /** @param  array<string, mixed>|null  $retailPackage */
    protected function tiersForRetailPackage(?array $retailPackage): array
    {
        if (! $retailPackage) {
            return [];
        }

        $raw = $retailPackage['pricing_tiers'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }

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
        if ((float) ($retailPackage['max_qty_measure'] ?? 0) > 0) {
            $tiers[] = [
                'min_qty' => 1.0,
                'max_qty' => (float) $retailPackage['max_qty_measure'],
                'measure_level' => 'small',
                'price_mode' => 'retail',
                'markup_price' => (float) ($retailPackage['markup_price'] ?? 0),
            ];
        }
        if ((float) ($retailPackage['wholesale_qty_measure'] ?? 0) > 0) {
            $tiers[] = [
                'min_qty' => (float) ($retailPackage['max_qty_measure'] ?? 0) + 0.001,
                'max_qty' => (float) $retailPackage['wholesale_qty_measure'],
                'measure_level' => 'middle',
                'price_mode' => 'wholesale',
                'markup_price' => (float) ($retailPackage['wholesale_markup_price'] ?? 0),
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

    /** @param  list<array<string, mixed>>  $tiers */
    protected function tiersWithPriceMode(array $tiers, string $priceMode): array
    {
        $mode = $this->normalizeTierPriceMode(['price_mode' => $priceMode]);

        return array_values(array_filter(
            $tiers,
            fn (array $tier) => $this->normalizeTierPriceMode($tier) === $mode,
        ));
    }

    /** @param  list<array<string, mixed>>  $tiers */
    protected function tierForMeasureLevel(array $tiers, string $level, ?string $priceMode = null): ?array
    {
        $matches = array_values(array_filter(
            $tiers,
            fn (array $tier) => ($tier['measure_level'] ?? 'small') === $level,
        ));
        if ($priceMode !== null) {
            $mode = $this->normalizeTierPriceMode(['price_mode' => $priceMode]);

            foreach ($matches as $tier) {
                if ($this->normalizeTierPriceMode($tier) === $mode) {
                    return $tier;
                }
            }

            return null;
        }

        return $matches[0] ?? null;
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
        if ($level === 'middle' && $middleFactor > 1) {
            return ($baseUnitPrice / $conversion) * $middleFactor;
        }

        return $baseUnitPrice / $conversion;
    }

    protected function smallUnitsPerLevel(float $conversion, float $middleFactor, string $level): float
    {
        if ($level === 'full' && $conversion > 1) {
            return $conversion;
        }
        if ($level === 'middle' && $middleFactor > 1) {
            return $middleFactor;
        }

        return 1.0;
    }

    /** @param  list<array<string, mixed>>  $tiers */
    protected function tierPriceAtMeasureLevel(
        float $baseUnitPrice,
        array $tier,
        float $conversion,
        float $middleFactor,
    ): float {
        $wholesaleBase = $this->wholesalePriceAtMeasureLevel(
            $baseUnitPrice,
            $conversion,
            $middleFactor,
            $tier['measure_level'] ?? 'small',
        );
        $markup = (float) ($tier['markup_price'] ?? 0);
        if ($this->normalizeTierPriceMode($tier) === 'wholesale') {
            return $wholesaleBase;
        }

        return $wholesaleBase + $markup;
    }

    /** @param  list<array<string, mixed>>  $tiers */
    protected function wholesaleTierPriceAtMeasureLevel(
        float $baseUnitPrice,
        array $tier,
        float $conversion,
        float $middleFactor,
    ): float {
        $wholesaleBase = $this->wholesalePriceAtMeasureLevel(
            $baseUnitPrice,
            $conversion,
            $middleFactor,
            $tier['measure_level'] ?? 'small',
        );
        $markup = (float) ($tier['markup_price'] ?? 0);

        return $wholesaleBase + $markup;
    }

    /** @param  list<array<string, mixed>>  $tiers */
    protected function priceForMeasureLevel(
        float $baseUnitPrice,
        array $tiers,
        float $conversion,
        float $middleFactor,
        string $level,
        bool $sellOnRetail,
        string $priceMode = 'retail',
    ): ?float {
        $wholesaleAtLevel = $this->wholesalePriceAtMeasureLevel(
            $baseUnitPrice,
            $conversion,
            $middleFactor,
            $level,
        );

        if (! $sellOnRetail || $tiers === []) {
            $wholesaleTier = $this->tierForMeasureLevel($tiers, $level, 'wholesale');
            if ($wholesaleTier) {
                return $this->wholesaleTierPriceAtMeasureLevel(
                    $baseUnitPrice,
                    $wholesaleTier,
                    $conversion,
                    $middleFactor,
                );
            }

            return $wholesaleAtLevel;
        }

        $retailTier = $this->tierForMeasureLevel($tiers, $level, $priceMode);
        if ($retailTier) {
            return $this->tierPriceAtMeasureLevel(
                $baseUnitPrice,
                $retailTier,
                $conversion,
                $middleFactor,
            );
        }

        return $wholesaleAtLevel;
    }

    /** @param  list<array<string, mixed>>  $tiers */
    protected function aboveDozensTierPrice(
        float $baseUnitPrice,
        array $tiers,
        float $conversion,
        float $middleFactor,
    ): ?float {
        $wholesaleTiers = $this->tiersWithPriceMode($tiers, 'wholesale');
        $tier = $this->tierForMeasureLevel($wholesaleTiers, 'middle')
            ?? ($wholesaleTiers[0] ?? null);
        if (! $tier) {
            return null;
        }

        return $this->wholesaleTierPriceAtMeasureLevel(
            $baseUnitPrice,
            $tier,
            $conversion,
            $middleFactor,
        );
    }

    /** @param  list<array<string, mixed>>  $tiers */
    protected function wholesaleColumnPrice(
        float $baseUnitPrice,
        array $tiers,
        float $conversion,
        float $middleFactor,
    ): float {
        $tier = $this->tierForMeasureLevel($tiers, 'full', 'wholesale')
            ?? $this->tierForMeasureLevel($this->tiersWithPriceMode($tiers, 'wholesale'), 'full')
            ?? ($this->tiersWithPriceMode($tiers, 'wholesale')[0] ?? null);

        if ($tier) {
            return $this->wholesaleTierPriceAtMeasureLevel(
                $baseUnitPrice,
                $tier,
                $conversion,
                $middleFactor,
            );
        }

        return $conversion > 1
            ? $baseUnitPrice
            : $this->wholesalePriceAtMeasureLevel($baseUnitPrice, $conversion, $middleFactor, 'small');
    }
}
