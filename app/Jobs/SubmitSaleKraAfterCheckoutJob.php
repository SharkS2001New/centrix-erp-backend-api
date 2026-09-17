<?php

namespace App\Jobs;

use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Sales\CheckoutKraSubmissionService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Fiscalize after the checkout HTTP response for channels that do not need the
 * eTIMS QR on the first reply (mobile field sales). POS still waits sync so the
 * thermal receipt can print with the QR.
 *
 * Not queued — afterResponse() runs in-process once the response is sent.
 */
class SubmitSaleKraAfterCheckoutJob
{
    use Dispatchable;

    public function __construct(
        public int $saleId,
        public int $userId,
        public ?string $buyerPin = null,
    ) {}

    public function handle(ErpContext $erp): void
    {
        $sale = Sale::query()->find($this->saleId);
        $user = User::query()->find($this->userId);
        if (! $sale || ! $user) {
            return;
        }

        if ($sale->kraResponse()->where('status', 'success')->exists()) {
            return;
        }

        try {
            $gate = $erp->gateForUser($user);
            app(CheckoutKraSubmissionService::class)->submitForSale(
                $sale,
                $gate,
                $this->buyerPin,
            );
        } catch (\Throwable $e) {
            Log::warning('Deferred KRA after mobile checkout failed', [
                'sale_id' => $this->saleId,
                'message' => $e->getMessage(),
            ]);
            report($e);
        }
    }
}
