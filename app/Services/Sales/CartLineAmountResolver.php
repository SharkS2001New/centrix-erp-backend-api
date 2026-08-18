<?php

namespace App\Services\Sales;

/**
 * Decide whether a client-sent cart line amount may replace server pricing.
 *
 * Classic external POS always posts `amount` (cash rounding). Blindly trusting it
 * previously persisted wildly wrong client totals (e.g. pack price × base qty).
 */
final class CartLineAmountResolver
{
    /**
     * Accept client amount only when it is a small nudge away from the server total
     * (cash rounding / float). Otherwise keep the server-computed amount.
     *
     * Wholesale/retail package markups (e.g. 100 → 110 /kg) must be kept. Reject only
     * pack-price × base-qty inflation (e.g. 3600 × 25 = 90,000).
     */
    public static function resolve(mixed $clientAmount, float $computedAmount, float $conversionFactor = 1.0): float
    {
        $computed = round(max(0.0, $computedAmount), 2);

        if ($clientAmount === null || $clientAmount === '') {
            return $computed;
        }

        $client = round(max(0.0, (float) $clientAmount), 2);
        $delta = abs($client - $computed);

        // Last-digit cash rounding is typically ≤ 5; allow a little headroom for floats.
        $tolerance = max(5.0, abs($computed) * 0.02);
        if ($delta <= $tolerance) {
            return $client;
        }

        // When the POS workspace line amount is lower than a naive unit×qty recompute, keep it.
        if ($client < $computed) {
            return $client;
        }

        $factor = max(1.0, $conversionFactor);
        if ($factor > 1.5 && $computed > 0.009) {
            $packInflated = round($computed * $factor, 2);
            $packTol = max(1.0, abs($packInflated) * 0.05);
            if (abs($client - $packInflated) <= $packTol) {
                return $computed;
            }
        }

        // Unknown UOM: still reject extreme pack×qty blow-ups (25×+), not 10% markups.
        if ($factor <= 1.5 && $computed > 0.009 && ($client / $computed) >= 8.0) {
            return $computed;
        }

        return $client;
    }
}
