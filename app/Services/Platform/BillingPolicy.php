<?php

namespace App\Services\Platform;

use App\Models\PlatformBillingSetting;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-admin token and offline rules. Prices here are defaults until an admin publishes them.
 */
class BillingPolicy
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'token_value_kes' => 1,
            'token_pack_tokens' => 500,
            'token_pack_price_kes' => 500,
            'expiry_policy' => 'admin',
            'low_balance_first_percent' => 20,
            'low_balance_second_percent' => 10,
            'grace_enabled' => true,
            'grace_max_tokens' => 25,
            'actions' => [
                ['key' => 'sales.invoice.create', 'label' => 'Create sales invoice', 'tokens' => 1, 'notes' => 'Standard operation'],
                ['key' => 'customers.update', 'label' => 'Update customer', 'tokens' => 1, 'notes' => 'Standard operation'],
                ['key' => 'purchasing.lpo.create', 'label' => 'Create purchase order', 'tokens' => 1, 'notes' => 'Standard operation'],
                ['key' => 'inventory.import', 'label' => 'Bulk import', 'tokens' => 0, 'notes' => 'Configurable. Based on record count when enabled.'],
                ['key' => 'sales.invoice.reverse', 'label' => 'Finalised invoice reversal', 'tokens' => 0, 'notes' => 'Requires platform review'],
            ],
            'offline' => [
                'enabled' => false,
                'max_allowance' => 100,
                'grant_hours' => 24,
                'sync_hours' => 72,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        $stored = null;
        if (Schema::hasTable('platform_billing_settings') && Schema::hasColumn('platform_billing_settings', 'policy')) {
            $stored = PlatformBillingSetting::current()?->policy;
        }

        return $this->normalize(is_array($stored) ? $stored : []);
    }

    /** @param  array<string, mixed>  $incoming */
    public function save(array $incoming): array
    {
        $normalized = $this->normalize($incoming);
        if (! Schema::hasTable('platform_billing_settings') || ! Schema::hasColumn('platform_billing_settings', 'policy')) {
            return $normalized;
        }

        $row = PlatformBillingSetting::current() ?? new PlatformBillingSetting();
        $row->policy = $normalized;
        $row->save();

        return $normalized;
    }

    /** @param  array<string, mixed>  $incoming */
    public function normalize(array $incoming): array
    {
        $defaults = self::defaults();
        $actions = [];
        foreach (is_array($incoming['actions'] ?? null) ? $incoming['actions'] : $defaults['actions'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $actions[] = [
                'key' => trim((string) ($row['key'] ?? '')) ?: str($label)->slug('.')->toString(),
                'label' => $label,
                'tokens' => max(0, (int) ($row['tokens'] ?? 0)),
                'notes' => trim((string) ($row['notes'] ?? '')),
            ];
        }

        $offline = is_array($incoming['offline'] ?? null) ? $incoming['offline'] : [];

        return [
            'token_value_kes' => max(1, (int) ($incoming['token_value_kes'] ?? $defaults['token_value_kes'])),
            'token_pack_tokens' => max(1, (int) ($incoming['token_pack_tokens'] ?? $defaults['token_pack_tokens'])),
            'token_pack_price_kes' => max(0, (int) ($incoming['token_pack_price_kes'] ?? $defaults['token_pack_price_kes'])),
            'expiry_policy' => in_array($incoming['expiry_policy'] ?? '', ['admin', 'never', '90_days'], true)
                ? $incoming['expiry_policy']
                : 'admin',
            'low_balance_first_percent' => min(100, max(0, (int) ($incoming['low_balance_first_percent'] ?? 20))),
            'low_balance_second_percent' => min(100, max(0, (int) ($incoming['low_balance_second_percent'] ?? 10))),
            'grace_enabled' => filter_var($incoming['grace_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'grace_max_tokens' => min(100, max(0, (int) ($incoming['grace_max_tokens'] ?? 25))),
            'actions' => $actions !== [] ? $actions : $defaults['actions'],
            'offline' => [
                'enabled' => filter_var($offline['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'max_allowance' => max(0, (int) ($offline['max_allowance'] ?? 100)),
                'grant_hours' => max(1, (int) ($offline['grant_hours'] ?? 24)),
                'sync_hours' => max(1, (int) ($offline['sync_hours'] ?? 72)),
            ],
        ];
    }
}
