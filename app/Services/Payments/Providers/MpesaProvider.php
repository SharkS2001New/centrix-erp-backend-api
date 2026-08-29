<?php

namespace App\Services\Payments\Providers;

use App\Models\MpesaPaybillAccount;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Till;
use App\Services\Mpesa\MpesaPaybillAccountService;
use App\Services\Mpesa\MpesaSettingsResolver;
use App\Services\MpesaService;
use App\Services\Payments\Contracts\PaymentProviderInterface;
use App\Services\Payments\PaymentAccountService;

class MpesaProvider implements PaymentProviderInterface
{
    public function __construct(
        protected PaymentAccountService $accounts,
        protected MpesaPaybillAccountService $mpesaAccounts,
    ) {}

    public function providerKey(): string
    {
        return PaymentAccount::PROVIDER_MPESA;
    }

    public function validateConfiguration(PaymentAccount $account): array
    {
        $mpesa = $this->resolveMpesaAccount($account);
        if (! $mpesa) {
            return [
                'ok' => false,
                'message' => 'M-Pesa payment account was not found.',
                'status' => 'missing',
            ];
        }

        $organization = Organization::query()->find((int) $account->organization_id);
        if (! $organization) {
            return [
                'ok' => false,
                'message' => 'Organization not found for this payment account.',
                'status' => 'missing',
            ];
        }

        $config = MpesaSettingsResolver::forBranch($organization, $mpesa->branch);
        $config = $this->mpesaAccounts->applyAccountToConfig($config, $mpesa);

        try {
            MpesaSettingsResolver::assertReadyForStkPush($config);
            $service = new MpesaService($config);
            $service->getAccessToken();

            return [
                'ok' => true,
                'message' => 'M-Pesa connection successful.',
                'status' => 'connected',
            ];
        } catch (\RuntimeException $e) {
            return [
                'ok' => false,
                'message' => $this->friendlyError($e->getMessage()),
                'status' => 'failed',
            ];
        }
    }

    public function initiatePayment(PaymentAccount $account, array $context): array
    {
        $mpesa = $this->resolveMpesaAccount($account);
        if (! $mpesa) {
            throw new \RuntimeException('M-Pesa payment account was not found.');
        }

        $organization = Organization::query()->findOrFail((int) $account->organization_id);
        $till = $mpesa->pos_till_id ? Till::query()->find((int) $mpesa->pos_till_id) : null;
        $service = MpesaService::forOrganization($organization, $mpesa->branch, $till);
        $phone = (string) ($context['phone_number'] ?? '');
        $amount = (int) ($context['amount'] ?? 0);
        $reference = (string) ($context['reference'] ?? 'Centrix payment');

        return $service->stkPush($phone, $amount, $reference);
    }

    public function handleCallback(PaymentAccount $account, array $payload): void
    {
        // Existing callback controllers remain authoritative; provider hook for future unified engine.
    }

    public function reconcile(PaymentAccount $account, array $externalRow): array
    {
        return [
            'matched' => false,
            'confidence' => 0,
            'notes' => 'Use accounting/mpesa-reconciliation for M-Pesa C2B matching.',
        ];
    }

    protected function resolveMpesaAccount(PaymentAccount $account): ?MpesaPaybillAccount
    {
        $model = $this->accounts->resolveProviderModel($account);

        return $model instanceof MpesaPaybillAccount ? $model : null;
    }

    protected function friendlyError(string $message): string
    {
        if (stripos($message, 'access token') !== false) {
            return 'Unable to connect to M-Pesa. Please verify the Consumer Key, Consumer Secret and shortcode.';
        }

        if (stripos($message, 'timeout') !== false) {
            return 'M-Pesa is taking longer than expected to respond. Try again shortly.';
        }

        return $message !== ''
            ? $message
            : 'Unable to connect to M-Pesa. Please verify your configuration.';
    }
}
