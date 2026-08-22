<?php

namespace App\Services\Equity;

use App\Models\Branch;
use App\Models\EquityBankAccount;
use App\Models\Organization;
use App\Models\RouteModel;
use App\Models\Sale;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-account Equity registry: routes / shops map to collection accounts.
 * Callbacks resolve by paybill / account number; reconciliation only matches
 * sales whose configured Equity account matches the payment.
 */
class EquityBankAccountService
{
    /**
     * @return array{
     *     organization_id: int,
     *     account: ?EquityBankAccount,
     *     branch_id: ?int,
     *     route_id: ?int
     * }|null
     */
    public function resolveFromCallbackPayload(array $payload): ?array
    {
        $accountNumber = $this->extractAccountNumber($payload);
        if ($accountNumber === '') {
            return null;
        }

        if (! Schema::hasTable('equity_bank_accounts')) {
            return null;
        }

        $account = $this->findActiveByAccountNumber($accountNumber);
        if (! $account) {
            return null;
        }

        return [
            'organization_id' => (int) $account->organization_id,
            'account' => $account,
            'branch_id' => $account->branch_id ? (int) $account->branch_id : null,
            'route_id' => $account->route_id ? (int) $account->route_id : null,
        ];
    }

    public function extractAccountNumber(array $payload): string
    {
        foreach ([
            'BusinessShortCode',
            'business_short_code',
            'paybill',
            'Paybill',
            'paybill_number',
            'account_number',
            'AccountNumber',
            'merchant_code',
            'MerchantCode',
            'primary_account_number',
            'BillTo',
            'bill_to',
        ] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    public function findActiveByAccountNumber(string $accountNumber): ?EquityBankAccount
    {
        $accountNumber = trim($accountNumber);
        if ($accountNumber === '' || ! Schema::hasTable('equity_bank_accounts')) {
            return null;
        }

        return EquityBankAccount::query()
            ->where('is_active', true)
            ->where(function ($query) use ($accountNumber) {
                $query->where('primary_account_number', $accountNumber)
                    ->orWhere('paybill_number', $accountNumber)
                    ->orWhere('account_number', $accountNumber);
            })
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    public function expectedAccountForSale(Sale $sale): ?EquityBankAccount
    {
        if (! Schema::hasTable('equity_bank_accounts')) {
            return null;
        }

        if ($sale->route_id && Schema::hasColumn('routes', 'equity_bank_account_id')) {
            $route = RouteModel::query()->find((int) $sale->route_id);
            if ($route?->equity_bank_account_id) {
                $account = EquityBankAccount::query()
                    ->where('id', (int) $route->equity_bank_account_id)
                    ->where('organization_id', (int) $sale->organization_id)
                    ->where('is_active', true)
                    ->first();
                if ($account) {
                    return $account;
                }
            }
        }

        if ($sale->branch_id && Schema::hasColumn('branches', 'equity_bank_account_id')) {
            $branch = Branch::query()->find((int) $sale->branch_id);
            if ($branch?->equity_bank_account_id) {
                $account = EquityBankAccount::query()
                    ->where('id', (int) $branch->equity_bank_account_id)
                    ->where('organization_id', (int) $sale->organization_id)
                    ->where('is_active', true)
                    ->first();
                if ($account) {
                    return $account;
                }
            }
        }

        return EquityBankAccount::query()
            ->where('organization_id', (int) $sale->organization_id)
            ->where('is_active', true)
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    public function paymentMatchesSale(
        ?EquityBankAccount $paymentAccount,
        ?string $paymentAccountNumber,
        Sale $sale,
    ): bool {
        $expected = $this->expectedAccountForSale($sale);

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

            if ($paymentAccount->route_id && (int) $paymentAccount->route_id === (int) $sale->route_id) {
                return $paymentAccount->matchesAccountNumber(
                    (string) ($paymentAccountNumber ?? $paymentAccount->primary_account_number),
                );
            }
            if ($paymentAccount->branch_id && (int) $paymentAccount->branch_id === (int) $sale->branch_id) {
                return $paymentAccount->matchesAccountNumber(
                    (string) ($paymentAccountNumber ?? $paymentAccount->primary_account_number),
                );
            }

            return false;
        }

        $code = trim((string) $paymentAccountNumber);

        return $code !== '' && $expected->matchesAccountNumber($code);
    }

    /**
     * @param  array<string, mixed>  $equity
     */
    public function syncDefaultFromOrgSettings(Organization $organization, array $equity): ?EquityBankAccount
    {
        if (! Schema::hasTable('equity_bank_accounts')) {
            return null;
        }

        $codes = [];
        foreach (['primary_account_number', 'paybill_number', 'account_number'] as $key) {
            $value = trim((string) ($equity[$key] ?? ''));
            if ($value !== '' && ! in_array($value, $codes, true)) {
                $codes[] = $value;
            }
        }
        if ($codes === []) {
            return null;
        }

        $primary = $codes[0];
        $existing = EquityBankAccount::query()
            ->where('organization_id', (int) $organization->id)
            ->where('is_default', true)
            ->orderBy('id')
            ->first();

        $attrs = [
            'name' => $existing?->name ?: 'Default Equity account',
            'primary_account_number' => $primary,
            'paybill_number' => trim((string) ($equity['paybill_number'] ?? '')) ?: null,
            'account_number' => trim((string) ($equity['account_number'] ?? '')) ?: null,
            'is_default' => true,
            'is_active' => true,
        ];

        $conflict = EquityBankAccount::query()
            ->where('primary_account_number', $primary)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->first();
        if ($conflict && (int) $conflict->organization_id !== (int) $organization->id) {
            throw new \InvalidArgumentException(
                "Equity account / paybill {$primary} is already registered to another organization.",
            );
        }

        if ($existing) {
            $existing->fill($attrs)->save();

            return $existing->fresh();
        }

        return EquityBankAccount::query()->create(array_merge($attrs, [
            'organization_id' => (int) $organization->id,
            'sort_order' => 0,
        ]));
    }
}
