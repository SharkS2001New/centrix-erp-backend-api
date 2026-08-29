<?php

namespace App\Services\Payments;

use App\Models\Branch;
use App\Models\EquityBankAccount;
use App\Models\MpesaPaybillAccount;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Till;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ModuleService;
use App\Services\Mpesa\MpesaPaybillAccountService;
use App\Services\Mpesa\MpesaSettingsResolver;
use App\Services\MpesaService;
use Illuminate\Support\Facades\Schema;

class CentrixPaymentsAvailabilityService
{
    public function __construct(
        protected ModuleService $modules,
        protected MpesaPaybillAccountService $mpesaAccounts,
    ) {}

    public static function forOrganization(Organization $organization): self
    {
        return new self(
            ModuleService::forOrganization($organization),
            app(MpesaPaybillAccountService::class),
        );
    }

    public function moduleEnabled(): bool
    {
        return $this->modules->centrixPaymentsEnabled();
    }

    public function assertModuleEnabled(): void
    {
        if (! $this->moduleEnabled()) {
            throw new \RuntimeException('Centrix Payments is not enabled for this organization.');
        }
    }

    public function mpesaStkEnabledForOrganization(Organization $organization): bool
    {
        if (! $this->moduleEnabled()) {
            return false;
        }

        $gate = $this->modules->gate();
        if (! $gate->mpesaStkPlatformEnabled()) {
            return false;
        }

        try {
            MpesaSettingsResolver::assertStkPushEnabledForOrganization($organization);
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }

    public function mpesaAccountConfigured(
        Organization $organization,
        ?Branch $branch = null,
        ?Till $till = null,
    ): bool {
        if (! Schema::hasTable('mpesa_paybill_accounts')) {
            return false;
        }

        if ($till) {
            $account = $this->mpesaAccounts->accountForPosTill($till);
            if ($account && $this->mpesaPaybillAccountReady($account)) {
                return true;
            }
        }

        if ($branch?->mpesa_paybill_account_id) {
            $account = MpesaPaybillAccount::query()
                ->where('id', (int) $branch->mpesa_paybill_account_id)
                ->where('organization_id', (int) $organization->id)
                ->where('is_active', true)
                ->first();
            if ($account && $this->mpesaPaybillAccountReady($account)) {
                return true;
            }
        }

        return MpesaPaybillAccount::query()
            ->where('organization_id', (int) $organization->id)
            ->where('is_active', true)
            ->where('enable_stk_push', true)
            ->exists();
    }

    public function mpesaStkAvailable(
        Organization $organization,
        ?Branch $branch = null,
        ?Till $till = null,
    ): bool {
        return $this->mpesaStkEnabledForOrganization($organization)
            && $this->mpesaAccountConfigured($organization, $branch, $till);
    }

    public function summary(Organization $organization): array
    {
        $mpesaConfigured = $this->mpesaAccountConfigured($organization);
        $equityConfigured = Schema::hasTable('equity_bank_accounts')
            && EquityBankAccount::query()
                ->where('organization_id', (int) $organization->id)
                ->where('is_active', true)
                ->exists();

        return [
            'module_enabled' => $this->moduleEnabled(),
            'mpesa_stk_available' => $this->mpesaStkAvailable($organization),
            'mpesa_configured' => $mpesaConfigured,
            'equity_configured' => $equityConfigured,
            'bank_accounts_count' => Schema::hasTable('payment_accounts')
                ? PaymentAccount::query()
                    ->where('organization_id', (int) $organization->id)
                    ->where('provider', PaymentAccount::PROVIDER_BANK)
                    ->where('status', PaymentAccount::STATUS_ACTIVE)
                    ->count()
                : 0,
        ];
    }

    protected function mpesaPaybillAccountReady(MpesaPaybillAccount $account): bool
    {
        if (! $account->is_active) {
            return false;
        }

        if (Schema::hasColumn('mpesa_paybill_accounts', 'enable_stk_push') && ! $account->enable_stk_push) {
            return false;
        }

        $config = app(MpesaPaybillAccountService::class)->applyAccountToConfig(
            MpesaSettingsResolver::defaults(),
            $account,
        );

        try {
            MpesaSettingsResolver::assertReadyForStkPush($config);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
