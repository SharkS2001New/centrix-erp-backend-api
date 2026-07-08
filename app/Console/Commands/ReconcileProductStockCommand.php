<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\OpeningStockService;
use App\Services\Inventory\ProductStockDenormService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileProductStockCommand extends Command
{
    protected $signature = 'inventory:reconcile-stock
                            {--organization_id= : Limit to one organization}
                            {--dry-run : Report mismatches without writing}';

    protected $description = 'Align current_stock ledger with legacy denormalized product stock and refresh denorm cache';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $orgFilter = $this->option('organization_id') ? (int) $this->option('organization_id') : null;

        $orgs = DB::table('organizations')
            ->when($orgFilter, fn ($q) => $q->where('id', $orgFilter))
            ->pluck('id');

        $fixed = 0;
        $denormSynced = 0;

        foreach ($orgs as $orgId) {
            $orgId = (int) $orgId;
            $branches = DB::table('branches')->where('organization_id', $orgId)->orderBy('id')->pluck('id');
            if ($branches->isEmpty()) {
                continue;
            }

            $defaultBranchId = (int) $branches->first();
            $actor = User::query()->where('organization_id', $orgId)->where('is_admin', true)->orderBy('id')->first()
                ?? User::query()->where('organization_id', $orgId)->orderBy('id')->first();

            if (! $actor) {
                $this->warn("Skipping org #{$orgId}: no user to post opening balances.");

                continue;
            }

            $products = Product::query()
                ->where('organization_id', $orgId)
                ->whereNull('deleted_at')
                ->get(['id', 'product_code', 'branch_id', 'stock_in_shop', 'stock_in_store']);

            foreach ($products as $product) {
                $branchId = (int) ($product->branch_id ?: $defaultBranchId);
                $denormShop = (float) $product->stock_in_shop;
                $denormStore = (float) $product->stock_in_store;
                $denormTotal = $denormShop + $denormStore;

                $row = DB::table('current_stock')
                    ->where('product_code', $product->product_code)
                    ->where('branch_id', $branchId)
                    ->first();

                $ledgerShop = (float) ($row->shop_quantity ?? 0);
                $ledgerStore = (float) ($row->store_quantity ?? 0);
                $ledgerTotal = $ledgerShop + $ledgerStore;

                if ($denormTotal > 0 && $ledgerTotal <= 0) {
                    $this->line("Org {$orgId} {$product->product_code}: denorm={$denormTotal}, ledger=0 — posting opening stock");
                    if (! $dryRun) {
                        app(OpeningStockService::class)->applyOnProductCreate($actor, $product->product_code, (int) $product->id, [
                            'branch_id' => $branchId,
                            'shop_quantity' => $denormShop,
                            'store_quantity' => $denormStore,
                        ]);
                    }
                    $fixed++;
                } elseif ($ledgerTotal > 0) {
                    if (! $dryRun) {
                        app(ProductStockDenormService::class)->syncFromCurrentStock($product->product_code, $branchId);
                    }
                    $denormSynced++;
                }
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Opening stock posted for {$fixed} product(s); denorm refreshed for {$denormSynced} product(s).");

        return self::SUCCESS;
    }
}
