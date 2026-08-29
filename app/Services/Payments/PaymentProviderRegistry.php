<?php

namespace App\Services\Payments;

use App\Models\PaymentAccount;
use App\Services\Payments\Contracts\PaymentProviderInterface;
use App\Services\Payments\Providers\BankStatementProvider;
use App\Services\Payments\Providers\EquityProvider;
use App\Services\Payments\Providers\MpesaProvider;
use InvalidArgumentException;

class PaymentProviderRegistry
{
    /** @var array<string, PaymentProviderInterface> */
    protected array $providers;

    public function __construct(
        MpesaProvider $mpesa,
        EquityProvider $equity,
        BankStatementProvider $bank,
    ) {
        $this->providers = [
            $mpesa->providerKey() => $mpesa,
            $equity->providerKey() => $equity,
            $bank->providerKey() => $bank,
        ];
    }

    public function forAccount(PaymentAccount $account): PaymentProviderInterface
    {
        $provider = $this->providers[$account->provider] ?? null;
        if (! $provider) {
            throw new InvalidArgumentException('Unsupported payment provider: ' . $account->provider);
        }

        return $provider;
    }

    public function get(string $providerKey): PaymentProviderInterface
    {
        $provider = $this->providers[$providerKey] ?? null;
        if (! $provider) {
            throw new InvalidArgumentException('Unsupported payment provider: ' . $providerKey);
        }

        return $provider;
    }
}
