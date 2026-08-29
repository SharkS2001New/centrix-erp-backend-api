<?php

namespace App\Services\Payments\Providers;

use App\Models\PaymentAccount;
use App\Services\Payments\Contracts\PaymentProviderInterface;

class BankStatementProvider implements PaymentProviderInterface
{
    public function providerKey(): string
    {
        return PaymentAccount::PROVIDER_BANK;
    }

    public function validateConfiguration(PaymentAccount $account): array
    {
        return [
            'ok' => $account->isActive(),
            'message' => $account->isActive()
                ? 'Bank payment account is active.'
                : 'Bank payment account is inactive.',
            'status' => $account->isActive() ? 'connected' : 'inactive',
        ];
    }

    public function initiatePayment(PaymentAccount $account, array $context): array
    {
        throw new \RuntimeException('Bank accounts do not support STK push.');
    }

    public function handleCallback(PaymentAccount $account, array $payload): void {}

    public function reconcile(PaymentAccount $account, array $externalRow): array
    {
        return [
            'matched' => false,
            'confidence' => 0,
            'notes' => 'Use accounting/bank-reconciliations for bank statement matching.',
        ];
    }
}
