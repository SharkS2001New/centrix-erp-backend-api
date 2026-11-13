<?php

namespace App\Services\Kra;

/**
 * Decides when checkout and other sale flows should call the on-prem KRA device.
 *
 * Device configuration ({@see isDeviceConfigured}) is separate from whether sales
 * are fiscalized ({@see isFiscalizationActive} / kra_sales_scope).
 *
 * Scope ({@see salesScope}):
 * - off — never fiscalize sales
 * - mobile — field mobile channel only (`mobile`)
 * - erp — POS + backoffice + hotel till (Centrix AS Mobile uses cart channel=`pos`)
 * - both — all sales channels
 */
class KraFiscalPolicy
{
    public const SCOPE_OFF = 'off';

    public const SCOPE_MOBILE = 'mobile';

    public const SCOPE_ERP = 'erp';

    public const SCOPE_BOTH = 'both';

    /** @var list<string> */
    public const SCOPES = [
        self::SCOPE_OFF,
        self::SCOPE_MOBILE,
        self::SCOPE_ERP,
        self::SCOPE_BOTH,
    ];

    /** Channels treated as ERP (POS / backoffice / hotel till). */
    private const ERP_CHANNELS = ['pos', 'backend', 'hotel', 'hospitality'];

    /** Channels treated as mobile / field sales. */
    private const MOBILE_CHANNELS = ['mobile'];

    /** @param  array<string, mixed>  $finance */
    public static function isDeviceConfigured(array $finance): bool
    {
        return ! empty($finance['enable_kra_device']);
    }

    /**
     * Normalized sales fiscalization scope.
     *
     * Legacy orgs only store default_submit_kra boolean → maps to both/off.
     *
     * @param  array<string, mixed>  $finance
     */
    public static function salesScope(array $finance): string
    {
        $raw = strtolower(trim((string) ($finance['kra_sales_scope'] ?? '')));
        if (in_array($raw, self::SCOPES, true)) {
            return $raw;
        }

        return (($finance['default_submit_kra'] ?? true) !== false)
            ? self::SCOPE_BOTH
            : self::SCOPE_OFF;
    }

    /**
     * Persist helper: keep default_submit_kra in sync for older readers.
     *
     * @param  array<string, mixed>  $finance
     * @return array<string, mixed>
     */
    public static function applySalesScope(array $finance, string $scope): array
    {
        $scope = in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_BOTH;
        $finance['kra_sales_scope'] = $scope;
        $finance['default_submit_kra'] = $scope !== self::SCOPE_OFF;

        return $finance;
    }

    /** @param  array<string, mixed>  $finance */
    public static function isFiscalizationActive(array $finance): bool
    {
        if (! self::isDeviceConfigured($finance)) {
            return false;
        }

        return self::salesScope($finance) !== self::SCOPE_OFF;
    }

    /**
     * Whether the given sale channel is covered by the org's KRA sales scope.
     *
     * @param  array<string, mixed>  $finance
     */
    public static function channelAllowsFiscalization(array $finance, ?string $channel): bool
    {
        $scope = self::salesScope($finance);
        if ($scope === self::SCOPE_OFF) {
            return false;
        }
        if ($scope === self::SCOPE_BOTH) {
            return true;
        }

        $normalized = self::normalizeChannel($channel);
        if ($normalized === '') {
            // Unknown channel: only "both" would allow; scope is mobile or erp here.
            return false;
        }

        if ($scope === self::SCOPE_MOBILE) {
            return in_array($normalized, self::MOBILE_CHANNELS, true);
        }

        // erp
        return in_array($normalized, self::ERP_CHANNELS, true);
    }

    public static function normalizeChannel(?string $channel): string
    {
        $channel = strtolower(trim((string) $channel));

        return match ($channel) {
            'pos', 'external_pos' => 'pos',
            'hotel_pos', 'hotel', 'hospitality' => 'hotel',
            'backend', 'backoffice', 'web' => 'backend',
            'mobile', 'centrix_as_mobile', 'as_mobile', 'field' => 'mobile',
            default => $channel,
        };
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
     * @param  bool|null  $explicitSubmit  Kept for API compatibility. Org KRA sales scope
     *                                     wins — External POS cannot opt out via a stale
     *                                     submit_kra=false. true still cannot force submit when
     *                                     org fiscalization is off for this channel.
     * @param  string|null  $channel  Sale/cart channel (pos, backend, mobile, …).
     */
    public static function shouldFiscalizeSale(
        array $finance,
        float $orderTotal,
        ?bool $explicitSubmit = null,
        ?string $channel = null,
    ): bool {
        if (! self::isDeviceConfigured($finance)) {
            return false;
        }

        if (! self::isFiscalizationActive($finance)) {
            return false;
        }

        if (! self::channelAllowsFiscalization($finance, $channel)) {
            return false;
        }

        if (self::isBypassed($finance, $orderTotal)) {
            return false;
        }

        return true;
    }
}
