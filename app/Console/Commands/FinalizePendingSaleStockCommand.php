<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Sales\CheckoutFinalizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recover sales that left soft holds after checkout because deferred stock deduct failed.
 */
class FinalizePendingSaleStockCommand extends Command
{
    protected $signature = 'inventory:finalize-pending-sale-stock
                            {--organization_id= : Limit to one organization}
                            {--limit=100 : Max sales to process}
                            {--dry-run : List matching sales without deducting}';

    protected $description = 'Complete pending stock deductions for sales still marked pending_stock_deduct / stock_balanced=0';

    public function handle(CheckoutFinalizationService $finalizer, ErpContext $erp): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $orgFilter = $this->option('organization_id') ? (int) $this->option('organization_id') : null;

        $query = Sale::query()
            ->where('stock_balanced', 0)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('fulfillment_meta->pending_stock_deduct', true)
                    ->orWhere('fulfillment_meta->pending_stock_deduct', 1)
                    ->orWhere('fulfillment_meta->pending_stock_deduct', 'true');
            })
            ->when($orgFilter, fn ($q) => $q->where('organization_id', $orgFilter))
            ->orderBy('id')
            ->limit($limit);

        $sales = $query->get();
        if ($sales->isEmpty()) {
            $this->info('No pending sale stock deductions found.');

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($sales as $sale) {
            $this->line("Sale #{$sale->id} order {$sale->order_num} (org {$sale->organization_id})");
            if ($dryRun) {
                continue;
            }

            $user = User::query()->find($sale->cashier_id)
                ?? User::query()->where('organization_id', $sale->organization_id)->where('is_admin', true)->orderBy('id')->first()
                ?? User::query()->where('organization_id', $sale->organization_id)->orderBy('id')->first();

            if (! $user) {
                $this->warn("  skipped — no actor user for org {$sale->organization_id}");
                $failed++;

                continue;
            }

            try {
                $gate = $erp->gateForUser($user);
                $finalizer->deductSaleStock($sale->fresh(['items']) ?? $sale, $user, $gate);
                $ok++;
                $this->info('  deducted');
            } catch (\Throwable $e) {
                $failed++;
                Log::error('inventory:finalize-pending-sale-stock failed', [
                    'sale_id' => $sale->id,
                    'message' => $e->getMessage(),
                ]);
                $this->error('  '.$e->getMessage());
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Processed {$sales->count()} sale(s); deducted {$ok}; failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
