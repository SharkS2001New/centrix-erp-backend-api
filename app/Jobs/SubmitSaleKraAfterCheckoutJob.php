<?php

namespace App\Jobs;

use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Sales\CheckoutKraSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fiscalize after mobile checkout without blocking the field-rep HTTP reply.
 *
 * With a real queue driver (redis/database/sqs), the job is pushed during
 * checkout so a worker can talk to Comstore in parallel with the response.
 * On the sync driver it is run via afterResponse() so the till still returns first.
 */
class SubmitSaleKraAfterCheckoutJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 45;

    public function __construct(
        public int $saleId,
        public int $userId,
        public ?string $buyerPin = null,
    ) {
        $this->onQueue((string) config('queue.names.kra', 'kra'));
    }

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
