<?php

namespace App\Services\Mpesa;

use App\Models\Branch;
use App\Models\MpesaPaybillAccount;
use App\Models\Organization;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\TemporaryCart;
use App\Models\Till;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-paybill registry: one org may have many Safaricom shortcodes
 * (routes / shops). Callbacks resolve by BusinessShortCode; reconciliation
 * only matches sales whose configured paybill matches the payment.
 */
class MpesaPaybillAccountService
{
    /**
     * @return array{
     *     organization_id: int,
     *     account: ?MpesaPaybillAccount,
     *     branch_id: ?int,
     *     route_id: ?int
     * }|null
     */
    public function resolveFromC2bPayload(array $payload): ?array
    {
        $shortCode = trim((string) (
            $payload['BusinessShortCode']
            ?? $payload['business_short_code']
            ?? ''
        ));

        if ($shortCode === '') {
            return null;
        }

        if (Schema::hasTable('mpesa_paybill_accounts')) {
            $account = $this->findActiveByShortCode($shortCode);
            if ($account) {
                return [
                    'organization_id' => (int) $account->organization_id,
                    'account' => $account,
                    'branch_id' => $account->branch_id ? (int) $account->branch_id : null,
                    'route_id' => $account->route_id ? (int) $account->route_id : null,
                    'till_id' => $account->pos_till_id ? (int) $account->pos_till_id : null,
                ];
            }
        }

        // Legacy fallback: org / branch settings shortcodes.
        $organizationId = MpesaSettingsResolver::organizationIdForC2bPayload($payload);
        if ($organizationId === null) {
            return null;
        }

        return [
            'organization_id' => $organizationId,
            'account' => null,
            'branch_id' => null,
            'route_id' => null,
            'till_id' => null,
        ];
    }

    public function findActiveByShortCode(string $shortCode): ?MpesaPaybillAccount
    {
        $shortCode = trim($shortCode);
        if ($shortCode === '' || ! Schema::hasTable('mpesa_paybill_accounts')) {
            return null;
        }

        return MpesaPaybillAccount::query()
            ->where('is_active', true)
            ->where(function ($query) use ($shortCode) {
                $query->where('primary_short_code', $shortCode)
                    ->orWhere('child_storecode', $shortCode)
                    ->orWhere('till_number', $shortCode)
                    ->orWhere('shortcode', $shortCode);
            })
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    public function expectedAccountForSale(Sale $sale): ?MpesaPaybillAccount
    {
        if (! Schema::hasTable('mpesa_paybill_accounts')) {
            return null;
        }

        if ($sale->till_id && Schema::hasColumn('tills', 'mpesa_paybill_account_id')) {
            $till = Till::query()->find((int) $sale->till_id);
            if ($till?->mpesa_paybill_account_id) {
                $account = MpesaPaybillAccount::query()
                    ->where('id', (int) $till->mpesa_paybill_account_id)
                    ->where('organization_id', (int) $sale->organization_id)
                    ->where('is_active', true)
                    ->first();
                if ($account) {
                    return $account;
                }
            }
        }

        if ($sale->route_id && Schema::hasColumn('routes', 'mpesa_paybill_account_id')) {
            $route = RouteModel::query()->find((int) $sale->route_id);
            if ($route?->mpesa_paybill_account_id) {
                $account = MpesaPaybillAccount::query()
                    ->where('id', (int) $route->mpesa_paybill_account_id)
                    ->where('organization_id', (int) $sale->organization_id)
                    ->where('is_active', true)
                    ->first();
                if ($account) {
                    return $account;
                }
            }
        }

        if ($sale->branch_id && Schema::hasColumn('branches', 'mpesa_paybill_account_id')) {
            $branch = Branch::query()->find((int) $sale->branch_id);
            if ($branch?->mpesa_paybill_account_id) {
                $account = MpesaPaybillAccount::query()
                    ->where('id', (int) $branch->mpesa_paybill_account_id)
                    ->where('organization_id', (int) $sale->organization_id)
                    ->where('is_active', true)
                    ->first();
                if ($account) {
                    return $account;
                }
            }
        }

        return MpesaPaybillAccount::query()
            ->where('organization_id', (int) $sale->organization_id)
            ->where('is_active', true)
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    public function accountForPosTill(?Till $till): ?MpesaPaybillAccount
    {
        if (! $till || ! Schema::hasTable('mpesa_paybill_accounts')) {
            return null;
        }

        if ($till->mpesa_paybill_account_id) {
            $account = MpesaPaybillAccount::query()
                ->where('id', (int) $till->mpesa_paybill_account_id)
                ->where('organization_id', (int) $till->organization_id)
                ->where('is_active', true)
                ->first();
            if ($account) {
                return $account;
            }
        }

        if (Schema::hasColumn('mpesa_paybill_accounts', 'pos_till_id')) {
            return MpesaPaybillAccount::query()
                ->where('organization_id', (int) $till->organization_id)
                ->where('pos_till_id', (int) $till->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    public function accountForCart(TemporaryCart $cart): ?MpesaPaybillAccount
    {
        if ($cart->till_id) {
            $till = Till::query()->find((int) $cart->till_id);
            $account = $this->accountForPosTill($till);
            if ($account) {
                return $account;
            }
        }

        if ($cart->branch_id && Schema::hasColumn('branches', 'mpesa_paybill_account_id')) {
            $branch = Branch::query()->find((int) $cart->branch_id);
            if ($branch?->mpesa_paybill_account_id) {
                return MpesaPaybillAccount::query()
                    ->where('id', (int) $branch->mpesa_paybill_account_id)
                    ->where('is_active', true)
                    ->first();
            }
        }

        $orgId = (int) ($cart->organization_id ?? 0);
        if ($orgId <= 0) {
            return null;
        }

        return MpesaPaybillAccount::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    /**
     * Overlay paybill shortcodes + STK flag onto org/branch Daraja credentials.
     *
     * @param  array<string, mixed>  $baseConfig
     * @return array<string, mixed>
     */
    public function applyAccountToConfig(array $baseConfig, ?MpesaPaybillAccount $account): array
    {
        if (! $account) {
            return MpesaSettingsResolver::normalize($baseConfig);
        }

        $config = $baseConfig;
        if (trim((string) $account->shortcode) !== '') {
            $config['shortcode'] = trim((string) $account->shortcode);
        } elseif (trim((string) $account->primary_short_code) !== '') {
            // Buy-goods STK often uses head-office shortcode; keep existing if set.
            if (trim((string) ($config['shortcode'] ?? '')) === '') {
                $config['shortcode'] = trim((string) $account->primary_short_code);
            }
        }
        if (trim((string) $account->till_number) !== '') {
            $config['till_number'] = trim((string) $account->till_number);
        } elseif (trim((string) $account->primary_short_code) !== '') {
            $config['till_number'] = trim((string) $account->primary_short_code);
        }
        if (trim((string) $account->child_storecode) !== '') {
            $config['child_storecode'] = trim((string) $account->child_storecode);
        } else {
            $config['child_storecode'] = trim((string) $account->primary_short_code);
        }

        if ($account->enable_stk_push !== null) {
            $config['enable_stk_push'] = (bool) $account->enable_stk_push;
        }

        return MpesaSettingsResolver::normalize($config);
    }

    /**
     * Whether an incoming payment's shortcode / account may settle this sale.
     * Exact when the sale (via route → branch → org default) has a configured paybill.
     */
    public function paymentMatchesSale(
        ?MpesaPaybillAccount $paymentAccount,
        ?string $paymentShortCode,
        Sale $sale,
    ): bool {
        $expected = $this->expectedAccountForSale($sale);

        // No configured paybill on sale path — keep legacy org-wide matching.
        if (! $expected) {
            if ($paymentAccount && (int) $paymentAccount->organization_id !== (int) $sale->organization_id) {
                return false;
            }

            return true;
        }

        if ($paymentAccount) {
            if ((int) $paymentAccount->id === (int) $expected->id) {
                return true;
            }

            // Account scoped to this sale's POS till / route / branch still counts as exact.
            if ($paymentAccount->pos_till_id && (int) $paymentAccount->pos_till_id === (int) $sale->till_id) {
                return $paymentAccount->matchesShortCode((string) ($paymentShortCode ?? $paymentAccount->primary_short_code));
            }
            if ($paymentAccount->route_id && (int) $paymentAccount->route_id === (int) $sale->route_id) {
                return $paymentAccount->matchesShortCode((string) ($paymentShortCode ?? $paymentAccount->primary_short_code));
            }
            if ($paymentAccount->branch_id && (int) $paymentAccount->branch_id === (int) $sale->branch_id) {
                return $paymentAccount->matchesShortCode((string) ($paymentShortCode ?? $paymentAccount->primary_short_code));
            }

            return false;
        }

        $code = trim((string) $paymentShortCode);

        return $code !== '' && $expected->matchesShortCode($code);
    }

    /**
     * Ensure org finance.mpesa shortcodes stay as a syncable default account.
     *
     * @param  array<string, mixed>  $mpesa
     */
    public function syncDefaultFromOrgSettings(Organization $organization, array $mpesa): ?MpesaPaybillAccount
    {
        if (! Schema::hasTable('mpesa_paybill_accounts')) {
            return null;
        }

        $codes = [];
        foreach (['child_storecode', 'till_number', 'shortcode'] as $key) {
            $value = trim((string) ($mpesa[$key] ?? ''));
            if ($value !== '' && ! in_array($value, $codes, true)) {
                $codes[] = $value;
            }
        }
        if ($codes === []) {
            return null;
        }

        $primary = $codes[0];
        $existing = MpesaPaybillAccount::query()
            ->where('organization_id', (int) $organization->id)
            ->where('is_default', true)
            ->orderBy('id')
            ->first();

        $attrs = [
            'name' => $existing?->name ?: 'Default paybill',
            'primary_short_code' => $primary,
            'shortcode' => trim((string) ($mpesa['shortcode'] ?? '')) ?: null,
            'till_number' => trim((string) ($mpesa['till_number'] ?? '')) ?: null,
            'child_storecode' => trim((string) ($mpesa['child_storecode'] ?? '')) ?: null,
            'is_default' => true,
            'is_active' => true,
        ];

        $conflict = MpesaPaybillAccount::query()
            ->where('primary_short_code', $primary)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->first();
        if ($conflict && (int) $conflict->organization_id !== (int) $organization->id) {
            throw new \InvalidArgumentException(
                "Paybill / till shortcode {$primary} is already registered to another organization.",
            );
        }

        if ($existing) {
            $existing->fill($attrs)->save();

            return $existing->fresh();
        }

        return MpesaPaybillAccount::query()->create(array_merge($attrs, [
            'organization_id' => (int) $organization->id,
            'sort_order' => 0,
        ]));
    }
}
