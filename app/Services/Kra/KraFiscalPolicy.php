<?php

namespace App\Services\Kra;

/**
 * Decides when checkout and other sale flows should call the on-prem KRA device.
 *
 * Device configuration ({@see isDeviceConfigured}) is separate from whether sales
 * are fiscalized ({@see isFiscalizationActive} / default_submit_kra).
 */
class KraFiscalPolicy
{
    /** @param  array<string, mixed>  $finance */
    public static function isDeviceConfigured(array $finance): bool
    {
        return ! empty($finance['enable_kra_device']);
    }

    /** @param  array<string, mixed>  $finance */
    public static function isFiscalizationActive(array $finance): bool
    {
        if (! self::isDeviceConfigured($finance)) {
            return false;
        }

        return ($finance['default_submit_kra'] ?? true) !== false;
    }

    /** @param  array<string, mixed>  $finance */
    public static function bypassAboveAmount(array $finance): ?float
    {
        $raw = $finance['kra_bypass_above_amount'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        $amount = (float) $raw;

        return $amount > 0 ? $amount : null;
    }

    /** @param  array<string, mixed>  $finance */
    public static function isBypassed(array $finance, float $orderTotal): bool
    {
        $threshold = self::bypassAboveAmount($finance);

        return $threshold !== null && $orderTotal >= $threshold;
    }

    /**
     * @param  array<string, mixed>  $finance
     * @param  bool|null  $explicitSubmit  Kept for API compatibility. Org "Use KRA device for
     *                                     sales" wins — External POS cannot opt out via a stale
     *                                     submit_kra=false. true still cannot force submit when
     *                                     org fiscalization is off.
     */
    public static function shouldFiscalizeSale(array $finance, float $orderTotal, ?bool $explicitSubmit = null): bool
    {
        if (! self::isDeviceConfigured($finance)) {
            return false;
        }

        if (! self::isFiscalizationActive($finance)) {
            return false;
        }

        if (self::isBypassed($finance, $orderTotal)) {
            return false;
        }

        return true;
    }
}
