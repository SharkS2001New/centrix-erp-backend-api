<?php

namespace App\Jobs;

use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\CheckoutFinalizationService;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Runs after the checkout HTTP response so POS can print immediately.
 * Not queued — afterResponse() executes in-process once the response is sent,
 * so no worker is required for stock/journal/notifications to finish.
 */
class FinalizeSaleAfterCheckoutJob
{
    use Dispatchable;

    public function __construct(
        public int $saleId,
        public int $userId,
        public bool $deductStock = false,
        public bool $runSideEffects = true,
    ) {}

    public function handle(CheckoutFinalizationService $finalizer): void
    {
        $sale = Sale::query()->find($this->saleId);
        $user = User::query()->find($this->userId);
        if (! $sale || ! $user) {
            return;
        }

        $finalizer->finalize(
            $sale,
            $user,
            $this->deductStock,
            $this->runSideEffects,
        );
    }
}
