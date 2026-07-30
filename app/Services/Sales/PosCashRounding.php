<?php

namespace App\Services\Sales;

/**
 * Light Stores POS cash rounding — mirrors src/lib/pos-cash-round.js.
 */
final class PosCashRounding
{
    public static function roundLightStoresAmount(float $value): float
    {
        if ($value <= 0) {
            return 0.0;
        }

        $asInt = (int) floor($value);
        $fraction = abs($value - $asInt);
        if ($fraction > 1e-9) {
            $last = ((int) floor(abs($value) * 10 + 1e-9)) % 10;
        } else {
            $last = abs($asInt) % 10;
        }

        if ($last < 2) {
            return (float) ($asInt - $last);
        }
        if ($last < 7) {
            return (float) ($asInt - $last + 5);
        }

        return (float) ($asInt - $last + 10);
    }

    /** @param  list<float|int|string|null>  $lineAmounts */
    public static function orderTotalFromLineAmounts(array $lineAmounts): float
    {
        if ($lineAmounts === []) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($lineAmounts as $amount) {
            $sum += self::roundLightStoresAmount((float) $amount);
        }

        return self::roundLightStoresAmount($sum);
    }
}
