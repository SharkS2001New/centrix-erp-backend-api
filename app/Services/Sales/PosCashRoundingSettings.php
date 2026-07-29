<?php

namespace App\Services\Sales;

/**
 * Light Stores last-digit cash rounding for external POS and create-order flows.
 *
 * Explicit platform flag wins. When unset on the org, classic external POS layout
 * keeps the legacy always-on behaviour.
 */
final class PosCashRoundingSettings
{
    /**
     * @param  array<string, mixed>  $mergedSales
     * @param  array<string, mixed>  $customSales  Raw org module_settings.sales (without defaults)
     */
    public static function enabled(array $mergedSales, array $customSales = []): bool
    {
        if (array_key_exists('enable_pos_cash_rounding', $customSales)) {
            return (bool) $customSales['enable_pos_cash_rounding'];
        }

        $layout = strtolower((string) ($mergedSales['external_pos_layout'] ?? 'modern'));

        return $layout === 'classic';
    }
}
