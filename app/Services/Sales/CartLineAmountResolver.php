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
     */
    public static function resolve(mixed $clientAmount, float $computedAmount): float
    {
        if ($clientAmount === null || $clientAmount === '') {
            return round(max(0.0, $computedAmount), 2);
        }

        $client = max(0.0, (float) $clientAmount);
        $computed = round(max(0.0, $computedAmount), 2);
        $delta = abs($client - $computed);

        // Last-digit cash rounding is typically ≤ 5; allow a little headroom for floats.
        $tolerance = max(5.0, abs($computed) * 0.02);
        if ($delta <= $tolerance) {
            return round($client, 2);
        }

        return $computed;
    }
}
