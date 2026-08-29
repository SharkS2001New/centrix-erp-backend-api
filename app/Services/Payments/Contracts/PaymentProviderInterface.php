<?php

namespace App\Services\Payments\Contracts;

use App\Models\Organization;
use App\Models\PaymentAccount;

interface PaymentProviderInterface
{
    public function providerKey(): string;

    /**
     * @return array{ok: bool, message: string, status?: string}
     */
    public function validateConfiguration(PaymentAccount $account): array;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function initiatePayment(PaymentAccount $account, array $context): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleCallback(PaymentAccount $account, array $payload): void;

    /**
     * @param  array<string, mixed>  $externalRow
     * @return array<string, mixed>
     */
    public function reconcile(PaymentAccount $account, array $externalRow): array;
}
