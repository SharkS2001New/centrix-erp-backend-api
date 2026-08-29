<?php

namespace App\Services\Payments\Providers;

use App\Models\PaymentAccount;
use App\Services\Payments\Contracts\PaymentProviderInterface;
use App\Services\Payments\PaymentAccountService;

class EquityProvider implements PaymentProviderInterface
{
    public function __construct(protected PaymentAccountService $accounts) {}

    public function providerKey(): string
    {
        return PaymentAccount::PROVIDER_EQUITY;
    }

    public function validateConfiguration(PaymentAccount $account): array
    {
        $equity = $this->accounts->resolveProviderModel($account);
        if (! $equity) {
            return [
                'ok' => false,
                'message' => 'Equity payment account was not found.',
                'status' => 'missing',
            ];
        }

        $connected = (bool) ($equity->has_own_callback_credentials ?? false);

        return [
            'ok' => $connected,
            'message' => $connected
                ? 'Equity account is configured.'
                : 'Configure Equity callback URL and shared secret.',
            'status' => $connected ? 'connected' : 'needs_setup',
        ];
    }

    public function initiatePayment(PaymentAccount $account, array $context): array
    {
        throw new \RuntimeException('Equity STK is not supported. Use paybill reconciliation.');
    }

    public function handleCallback(PaymentAccount $account, array $payload): void {}

    public function reconcile(PaymentAccount $account, array $externalRow): array
    {
        return [
            'matched' => false,
            'confidence' => 0,
            'notes' => 'Use accounting/equity-reconciliation for Equity paybill matching.',
        ];
    }
}
