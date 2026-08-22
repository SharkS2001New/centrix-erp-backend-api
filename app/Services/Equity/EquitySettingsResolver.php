<?php

namespace App\Services\Equity;

use App\Models\Organization;

class EquitySettingsResolver
{
    /**
     * @param  array<string, mixed>  $equity
     * @return array<string, mixed>
     */
    public static function normalize(array $equity): array
    {
        $defaults = config('erp.module_settings_defaults.finance.equity', []);

        return array_merge($defaults, $equity, [
            'enable_paybill_reconciliation' => ($equity['enable_paybill_reconciliation'] ?? $defaults['enable_paybill_reconciliation'] ?? false) === true
                || ($equity['enable_paybill_reconciliation'] ?? null) === 1
                || ($equity['enable_paybill_reconciliation'] ?? null) === '1',
            'auto_apply_order_reference' => ($equity['auto_apply_order_reference'] ?? $defaults['auto_apply_order_reference'] ?? true) !== false,
            'payment_account_name' => trim((string) ($equity['payment_account_name'] ?? $defaults['payment_account_name'] ?? '')),
            'payment_account_hint' => trim((string) ($equity['payment_account_hint'] ?? $defaults['payment_account_hint'] ?? '')),
            'callback_url' => trim((string) ($equity['callback_url'] ?? '')),
            'primary_account_number' => trim((string) ($equity['primary_account_number'] ?? '')),
            'paybill_number' => trim((string) ($equity['paybill_number'] ?? '')),
            'account_number' => trim((string) ($equity['account_number'] ?? '')),
            'callback_shared_secret' => (string) ($equity['callback_shared_secret'] ?? ''),
        ]);
    }

    public static function forOrganization(Organization $organization): array
    {
        $finance = is_array($organization->module_settings['finance'] ?? null)
            ? $organization->module_settings['finance']
            : [];
        $equity = is_array($finance['equity'] ?? null) ? $finance['equity'] : [];

        return self::normalize($equity);
    }

    public static function isPaybillReconciliationEnabledForOrganization(Organization $organization): bool
    {
        return (bool) (self::forOrganization($organization)['enable_paybill_reconciliation'] ?? false);
    }

    public static function paymentAccountHintForOrganization(Organization $organization): string
    {
        $hint = trim((string) (self::forOrganization($organization)['payment_account_hint'] ?? ''));

        return $hint !== '' ? $hint : 'Enter your order number (e.g. S12)';
    }

    /**
     * @param  array<string, mixed>  $equity
     * @return array<string, mixed>
     */
    public static function maskForClient(array $equity): array
    {
        $normalized = self::normalize($equity);
        if (trim((string) ($normalized['callback_shared_secret'] ?? '')) !== '') {
            $normalized['callback_shared_secret'] = '********';
        }

        return $normalized;
    }
}
