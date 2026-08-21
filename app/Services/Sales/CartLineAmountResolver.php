<?php

namespace App\Services\Sales;

/**
 * Decide whether a client-sent cart line amount may replace server pricing.
 *
 * Classic external POS always posts `amount` (cash rounding). Blindly trusting it
 * previously persisted wildly wrong client totals (e.g. pack price × base qty).
 * Blindly preferring any lower client total also stuck a 1kg price onto a 45kg line.
 */
final class CartLineAmountResolver
{
    /**
     * Accept client amount only when it is a small nudge away from the server total
     * (cash rounding / float), a legitimate package markup, or clearly correcting a
     * pack-price × base-qty server blow-up. Otherwise keep the server-computed amount.
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

        $factor = max(1.0, $conversionFactor);

        if ($client > $computed) {
            // Reject pack-price × base-qty inflation (e.g. 3600 × 25 = 90,000).
            if ($factor > 1.5 && $computed > 0.009) {
                $packInflated = round($computed * $factor, 2);
                $packTol = max(1.0, abs($packInflated) * 0.05);
                if (abs($client - $packInflated) <= $packTol) {
                    return $computed;
                }
            }

            // Unknown UOM: still reject extreme pack×qty blow-ups (8×+), not ~10% markups.
            if ($factor <= 1.5 && $computed > 0.009 && ($client / $computed) >= 8.0) {
                return $computed;
            }

            // Wholesale/retail package markups above catalog (e.g. 100 → 110).
            return $client;
        }

        // Client lower than server: only trust it when the server total looks like a
        // pack-price × kg blow-up of the correct line (client × conversion ≈ computed).
        // Do NOT accept arbitrary underpricing (1kg total posted on a 45kg line).
        if ($computed > 0.009 && $client > 0.009) {
            if ($factor > 1.5) {
                $asPackInflated = round($client * $factor, 2);
                $packTol = max(1.0, abs($asPackInflated) * 0.05);
                if (abs($computed - $asPackInflated) <= $packTol) {
                    return $client;
                }
            }

            // No conversion hint: extreme server blow-up vs a sane client line.
            if ($factor <= 1.5 && ($computed / $client) >= 8.0) {
                return $client;
            }
        }

        return $computed;
    }
}
