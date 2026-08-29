<?php

namespace App\Services\Payments;

use App\Models\EquityBankAccount;
use App\Models\MpesaPaybillAccount;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Till;
use App\Services\Mpesa\MpesaPaybillAccountService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class PaymentAccountService
{
    public const TYPE_MPESA_TILL = 'mpesa_till';

    public const TYPE_MPESA_PAYBILL = 'mpesa_paybill';

    public const TYPE_EQUITY_PAYBILL = 'equity_paybill';

    public const TYPE_BANK_ACCOUNT = 'bank_account';

    public function __construct(protected MpesaPaybillAccountService $mpesaAccounts) {}

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PaymentAccount>
     */
    public function listForOrganization(int $organizationId, ?string $provider = null)
    {
        $query = PaymentAccount::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('is_default')
            ->orderBy('account_name');

        if ($provider) {
            $query->where('provider', $provider);
        }

        return $query->get();
    }

    public function findForOrganization(int $organizationId, int $paymentAccountId): ?PaymentAccount
    {
        return PaymentAccount::query()
            ->where('organization_id', $organizationId)
            ->where('id', $paymentAccountId)
            ->first();
    }

    public function resolveForPosTill(Till $till, string $provider = PaymentAccount::PROVIDER_MPESA): ?PaymentAccount
    {
        if ($provider === PaymentAccount::PROVIDER_MPESA) {
            $mpesa = $this->mpesaAccounts->accountForPosTill($till);
            if (! $mpesa) {
                return null;
            }

            return $this->findByProviderAccount($till->organization_id, MpesaPaybillAccount::class, (int) $mpesa->id);
        }

        return PaymentAccount::query()
            ->where('organization_id', (int) $till->organization_id)
            ->where('provider', $provider)
            ->where('branch_id', $till->branch_id)
            ->where('status', PaymentAccount::STATUS_ACTIVE)
            ->orderByDesc('is_default')
            ->first();
    }

    public function findByProviderAccount(int $organizationId, string $providerAccountType, int $providerAccountId): ?PaymentAccount
    {
        return PaymentAccount::query()
            ->where('organization_id', $organizationId)
            ->where('provider_account_type', $providerAccountType)
            ->where('provider_account_id', $providerAccountId)
            ->first();
    }

    public function syncOrganization(int $organizationId): int
    {
        $synced = 0;
        if (Schema::hasTable('mpesa_paybill_accounts')) {
            $rows = MpesaPaybillAccount::query()->where('organization_id', $organizationId)->get();
            foreach ($rows as $row) {
                $this->upsertFromMpesaPaybill($row);
                $synced++;
            }
        }
        if (Schema::hasTable('equity_bank_accounts')) {
            $rows = EquityBankAccount::query()->where('organization_id', $organizationId)->get();
            foreach ($rows as $row) {
                $this->upsertFromEquityBank($row);
                $synced++;
            }
        }

        return $synced;
    }

    public function upsertFromMpesaPaybill(MpesaPaybillAccount $account): PaymentAccount
    {
        $accountType = trim((string) ($account->till_number ?? '')) !== ''
            ? self::TYPE_MPESA_TILL
            : self::TYPE_MPESA_PAYBILL;

        return PaymentAccount::query()->updateOrCreate(
            [
                'organization_id' => (int) $account->organization_id,
                'provider' => PaymentAccount::PROVIDER_MPESA,
                'provider_account_type' => MpesaPaybillAccount::class,
                'provider_account_id' => (int) $account->id,
            ],
            [
                'branch_id' => $account->branch_id ? (int) $account->branch_id : null,
                'account_type' => $accountType,
                'account_name' => (string) ($account->name ?: 'M-Pesa account'),
                'account_number' => $account->till_number ?: null,
                'shortcode' => $account->primary_short_code ?: $account->shortcode,
                'status' => $account->is_active ? PaymentAccount::STATUS_ACTIVE : PaymentAccount::STATUS_INACTIVE,
                'is_default' => (bool) $account->is_default,
                'auto_match_payments' => true,
                'metadata' => [
                    'pos_till_id' => $account->pos_till_id,
                    'route_id' => $account->route_id,
                    'enable_stk_push' => $account->enable_stk_push ?? true,
                ],
            ],
        );
    }

    public function upsertFromEquityBank(EquityBankAccount $account): PaymentAccount
    {
        return PaymentAccount::query()->updateOrCreate(
            [
                'organization_id' => (int) $account->organization_id,
                'provider' => PaymentAccount::PROVIDER_EQUITY,
                'provider_account_type' => EquityBankAccount::class,
                'provider_account_id' => (int) $account->id,
            ],
            [
                'branch_id' => $account->branch_id ? (int) $account->branch_id : null,
                'account_type' => self::TYPE_EQUITY_PAYBILL,
                'account_name' => (string) ($account->name ?: 'Equity account'),
                'account_number' => $account->primary_account_number ?: $account->account_number,
                'shortcode' => $account->paybill_number,
                'status' => $account->is_active ? PaymentAccount::STATUS_ACTIVE : PaymentAccount::STATUS_INACTIVE,
                'is_default' => (bool) $account->is_default,
                'auto_match_payments' => true,
                'metadata' => [
                    'route_id' => $account->route_id,
                ],
            ],
        );
    }

    public function resolveProviderModel(PaymentAccount $account): ?Model
    {
        if (! class_exists($account->provider_account_type)) {
            return null;
        }

        return $account->provider_account_type::query()
            ->where('organization_id', (int) $account->organization_id)
            ->where('id', (int) $account->provider_account_id)
            ->first();
    }

    public function toApiArray(PaymentAccount $account): array
    {
        $provider = $this->resolveProviderModel($account);
        $connected = false;
        if ($provider instanceof MpesaPaybillAccount) {
            $connected = $provider->has_own_daraja_credentials || $provider->has_consumer_secret;
        } elseif ($provider instanceof EquityBankAccount) {
            $connected = $provider->has_own_callback_credentials;
        }

        return [
            'id' => $account->id,
            'organization_id' => $account->organization_id,
            'branch_id' => $account->branch_id,
            'provider' => $account->provider,
            'account_type' => $account->account_type,
            'account_name' => $account->account_name,
            'account_number' => $account->account_number,
            'shortcode' => $account->shortcode,
            'status' => $account->status,
            'is_default' => $account->is_default,
            'auto_match_payments' => $account->auto_match_payments,
            'metadata' => $account->metadata ?? [],
            'provider_account_id' => $account->provider_account_id,
            'connection_status' => $connected ? 'connected' : 'needs_setup',
            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }
}
