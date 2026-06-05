<?php

namespace App\Support;

use App\Models\Product;
use App\Models\RetailPackageSetting;

final class RetailPricing
{
    /**
     * @return array<int, array{min_qty: float, max_qty: ?float, measure_level: string, markup_price: float}>
     */
    public static function tiersFor(RetailPackageSetting $rps): array
    {
        $tiers = $rps->pricing_tiers;
        if (is_array($tiers) && count($tiers) > 0) {
            return self::normalizeTiers($tiers);
        }

        return self::legacyTiers($rps);
    }

    /**
     * @param  array<int, array<string, mixed>>  $tiers
     * @return array<int, array{min_qty: float, max_qty: ?float, measure_level: string, markup_price: float}>
     */
    public static function normalizeTiers(array $tiers): array
    {
        $normalized = [];
        foreach ($tiers as $tier) {
            if (! is_array($tier)) {
                continue;
            }
            $min = (float) ($tier['min_qty'] ?? 0);
            $maxRaw = $tier['max_qty'] ?? null;
            $max = $maxRaw === null || $maxRaw === '' ? null : (float) $maxRaw;
            $normalized[] = [
                'min_qty' => $min,
                'max_qty' => $max,
                'measure_level' => (string) ($tier['measure_level'] ?? 'small'),
                'markup_price' => (float) ($tier['markup_price'] ?? 0),
            ];
        }

        usort($normalized, fn ($a, $b) => $a['min_qty'] <=> $b['min_qty']);

        return $normalized;
    }

    /**
     * @return array<int, array{min_qty: float, max_qty: ?float, measure_level: string, markup_price: float}>
     */
    public static function legacyTiers(RetailPackageSetting $rps): array
    {
        $tiers = [];
        if ((float) ($rps->max_qty_measure ?? 0) > 0) {
            $tiers[] = [
                'min_qty' => 1,
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

    /**
     * @param  array<int, array{min_qty: float, max_qty: ?float, measure_level: string, markup_price: float}>  $tiers
     * @return array{min_qty: float, max_qty: ?float, measure_level: string, markup_price: float}|null
     */
    public static function tierForQuantity(array $tiers, float $quantity): ?array
    {
        foreach ($tiers as $tier) {
            $min = (float) $tier['min_qty'];
            $max = $tier['max_qty'];
            if ($quantity + 0.0001 < $min) {
                continue;
            }
            if ($max !== null && $quantity > $max + 0.0001) {
                continue;
            }

            return $tier;
        }

        return null;
    }

    public static function linePrice(Product $product, RetailPackageSetting $rps, float $quantity, bool $isRetailLine): float
    {
        $base = (float) $product->unit_price;

        if (! $isRetailLine) {
            return round($base * $quantity, 2);
        }

        $tier = self::tierForQuantity(self::tiersFor($rps), $quantity);
        if (! $tier) {
            return round($base * $quantity, 2);
        }

        $perUnit = $base + (float) $tier['markup_price'];

        return round($perUnit * $quantity, 2);
    }
}
