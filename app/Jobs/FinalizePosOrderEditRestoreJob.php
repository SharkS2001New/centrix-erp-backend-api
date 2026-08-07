<?php

namespace App\Jobs;

use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Sales\PosOrderEditService;
use App\Services\Sales\SaleInventoryRestorer;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * After restore-to-cart HTTP response: reverse sale stock and/or issue the KRA
 * credit note so the till is not blocked on ledger or device latency.
 *
 * Not queued — afterResponse() runs in-process once the response is sent.
 * Checkout still calls fiscalVoidBeforeEdit synchronously as a safety net.
 */
class FinalizePosOrderEditRestoreJob
{
    use Dispatchable;

    public function __construct(
        public int $saleId,
        public int $userId,
        public bool $reverseStock = false,
        public bool $voidKra = false,
    ) {}

    public function handle(
        PosOrderEditService $edits,
        ErpContext $erp,
        SaleInventoryRestorer $inventory,
    ): void {
        $sale = Sale::query()->find($this->saleId);
        $user = User::query()->find($this->userId);
        if (! $sale || ! $user) {
            return;
        }

        if ($this->reverseStock) {
            try {
                $inventory->reverseForPosEdit($sale->fresh() ?? $sale, $user);
                $sale = $sale->fresh() ?? $sale;
                $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
                unset($meta['stock_reverse_pending']);
                $sale->update([
                    'stock_balanced' => 0,
                    'fulfillment_meta' => $meta,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Background POS edit stock reverse failed', [
                    'sale_id' => $this->saleId,
                    'user_id' => $this->userId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (! $this->voidKra) {
            return;
        }

        try {
            $gate = $erp->gateForUser($user);
            $edits->fiscalVoidBeforeEdit($sale->fresh() ?? $sale, $user, $gate);
        } catch (\Throwable $e) {
            Log::warning('Background POS edit KRA void failed', [
                'sale_id' => $this->saleId,
                'user_id' => $this->userId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
