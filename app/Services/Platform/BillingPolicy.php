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
            'tokens_enabled' => true,
            // When payment is missing or expired: sign in on Package 1 (true), or refuse login (false).
            'allow_unpaid_login' => true,
            'token_value_kes' => 1,
            'token_pack_tokens' => 500,
            'token_pack_price_kes' => 500,
            'expiry_policy' => 'admin',
            'low_balance_first_percent' => 20,
            'low_balance_second_percent' => 10,
            'grace_enabled' => true,
            'grace_max_tokens' => 25,
            'sale_tokens' => 1,
            'write_costs' => [
                'create' => 1,
                'update' => 1,
                'delete' => 1,
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

    public function tokensEnabled(): bool
    {
        return $this->tokensEnabledFrom($this->current());
    }

    /** @param  array<string, mixed>  $policy */
    public function tokensEnabledFrom(array $policy): bool
    {
        return ($policy['tokens_enabled'] ?? true) === true;
    }

    /** Unpaid organisations may sign in and use Package 1. */
    public function allowsUnpaidLogin(): bool
    {
        return $this->allowsUnpaidLoginFrom($this->current());
    }

    /** @param  array<string, mixed>  $policy */
    public function allowsUnpaidLoginFrom(array $policy): bool
    {
        return ($policy['allow_unpaid_login'] ?? true) === true;
    }

    /** Tokens spent once, when a completed sale is saved online or an offline order syncs. */
    public function saleCost(): int
    {
        return (int) $this->current()['sale_tokens'];
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
        $offline = is_array($incoming['offline'] ?? null) ? $incoming['offline'] : [];
        $writeCosts = is_array($incoming['write_costs'] ?? null) ? $incoming['write_costs'] : [];

        return [
            'tokens_enabled' => array_key_exists('tokens_enabled', $incoming)
                ? filter_var($incoming['tokens_enabled'], FILTER_VALIDATE_BOOLEAN)
                : (bool) $defaults['tokens_enabled'],
            'allow_unpaid_login' => array_key_exists('allow_unpaid_login', $incoming)
                ? filter_var($incoming['allow_unpaid_login'], FILTER_VALIDATE_BOOLEAN)
                : (bool) $defaults['allow_unpaid_login'],
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
            'sale_tokens' => $this->saleTokens($incoming, $writeCosts, (int) $defaults['sale_tokens']),
            'write_costs' => [
                'create' => $this->writeCost($writeCosts, 'create', $defaults['write_costs']['create']),
                'update' => $this->writeCost($writeCosts, 'update', $defaults['write_costs']['update']),
                'delete' => $this->writeCost($writeCosts, 'delete', $defaults['write_costs']['delete']),
            ],
            'offline' => [
                'enabled' => filter_var($offline['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'max_allowance' => max(0, (int) ($offline['max_allowance'] ?? 100)),
                'grant_hours' => max(1, (int) ($offline['grant_hours'] ?? 24)),
                'sync_hours' => max(1, (int) ($offline['sync_hours'] ?? 72)),
            ],
        ];
    }

    /**
     * Older policies stored a create/update/delete price. A sale uses the create price until sale_tokens is saved.
     *
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $writeCosts
     */
    protected function saleTokens(array $incoming, array $writeCosts, int $fallback): int
    {
        if (array_key_exists('sale_tokens', $incoming)) {
            return min(1000, max(0, (int) $incoming['sale_tokens']));
        }
        if (array_key_exists('create', $writeCosts)) {
            return min(1000, max(0, (int) $writeCosts['create']));
        }

        return $fallback;
    }

    /** @param  array<string, mixed>  $writeCosts */
    protected function writeCost(array $writeCosts, string $verb, int $fallback): int
    {
        return min(1000, max(0, (int) ($writeCosts[$verb] ?? $fallback)));
    }
}
