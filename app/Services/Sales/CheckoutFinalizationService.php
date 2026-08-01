<?php

namespace App\Services\Sales;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Accounting\SaleJournalService;
use App\Services\Audit\OperationalAuditService;
use App\Services\Cache\CompletedSalesCacheService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Fulfillment\AutoTripAssignmentService;
use App\Services\Notifications\CustomerNotificationService;
use App\Services\Sales\MobileSalesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Post-checkout work that must not block the cashier: stock ledger, journals,
 * customer SMS/email, trip assignment, and cache invalidation.
 *
 * Stock stays soft-held via reservations until deductSaleStock runs.
 */
class CheckoutFinalizationService
{
    use HandlesInventory;

    public function __construct(protected ErpContext $erp) {}

    public function finalize(
        Sale $sale,
        User $user,
        bool $deductStock,
        bool $runSideEffects,
    ): void {
        $gate = $this->erp->gateForUser($user);

        if ($deductStock) {
            try {
                $this->deductSaleStock($sale->fresh(['items']) ?? $sale, $user, $gate);
            } catch (Throwable $e) {
                Log::error('Deferred checkout stock deduction failed', [
                    'sale_id' => $sale->id,
                    'order_num' => $sale->order_num,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        if (! $runSideEffects) {
            return;
        }

        $sale = $sale->fresh(['items', 'payments']) ?? $sale;

        try {
            app(SaleJournalService::class)->postIfEnabled($sale, $user, $gate);
        } catch (Throwable $e) {
            Log::warning('Deferred sale journal failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $organization = Organization::find($user->organization_id);
            if ($organization) {
                app(CustomerNotificationService::class)->notifyOrderPlaced($sale, $organization);
            }
        } catch (Throwable $e) {
            Log::warning('Deferred order-placed notification failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            app(OperationalAuditService::class)->logSaleCheckout($user, $sale);
        } catch (Throwable $e) {
            Log::warning('Deferred checkout audit failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            if ($sale->status !== 'pending_approval') {
                app(AutoTripAssignmentService::class)->tryAssignSale($sale, $user);
            }
        } catch (Throwable $e) {
            Log::warning('Deferred auto-trip assignment failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            app(MobileSalesService::class)->invalidateDashboardForUser($user);
            app(CompletedSalesCacheService::class)->invalidateForSale($sale);
        } catch (Throwable $e) {
            Log::warning('Deferred checkout cache invalidation failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deductSaleStock(Sale $sale, User $user, CapabilityGate $gate): void
    {
        if ($sale->stock_balanced) {
            $this->clearPendingStockDeductFlag($sale);

            return;
        }

        $inventorySettings = $gate->moduleSettings('inventory');
        $salesSettings = $gate->moduleSettings('sales');
        $txnType = $this->saleTransactionType((string) ($sale->channel ?: 'pos'));
        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);
        $items = $sale->items ?? SaleItem::query()->where('sale_id', $sale->id)->get();

        DB::transaction(function () use (
            $sale,
            $user,
            $items,
            $inventorySettings,
            $salesSettings,
            $txnType,
            $allowBelowStock,
        ) {
            $locked = Sale::query()->lockForUpdate()->find($sale->id);
            if (! $locked || $locked->stock_balanced) {
                return;
            }

            foreach ($items as $item) {
                $product = $this->orgProduct((int) $user->organization_id, (string) $item->product_code);
                $location = $product
                    ? $this->resolveSaleLineStockLocation(
                        (string) $sale->channel,
                        $inventorySettings,
                        $salesSettings,
                        $product,
                        (bool) $item->on_wholesale_retail,
                    )
                    : $this->saleLineStockLocation(
                        (string) $sale->channel,
                        $inventorySettings,
                        $salesSettings,
                        (bool) $item->on_wholesale_retail,
                    );

                $unitCost = max(0, (float) ($product?->last_cost_price ?? 0));

                $this->postStockLedger([
                    'branch_id' => $sale->branch_id,
                    'product_code' => $item->product_code,
                    'stock_location' => $location,
                    'transaction_type' => $txnType,
                    'reference_type' => 'sale',
                    'reference_id' => $sale->id,
                    'quantity_change' => -abs((float) $item->quantity),
                    'unit_cost' => $unitCost > 0 ? $unitCost : null,
                    'created_by' => $user->id,
                ], $allowBelowStock);
            }

            $locked->update(['stock_balanced' => 1]);
            $this->releaseSaleReservations((int) $locked->id);
            $this->clearPendingStockDeductFlag($locked);
        });
    }

    protected function clearPendingStockDeductFlag(Sale $sale): void
    {
        $meta = $sale->fulfillment_meta;
        if (! is_array($meta) || empty($meta['pending_stock_deduct'])) {
            return;
        }
        unset($meta['pending_stock_deduct']);
        $sale->update(['fulfillment_meta' => $meta === [] ? null : $meta]);
    }
}
