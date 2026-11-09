<?php

namespace App\Services\Sales;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\LoyaltyCard;
use App\Models\MpesaIncomingPayment;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\SaleJournalService;
use App\Services\Audit\OperationalAuditService;
use App\Services\Cache\CompletedSalesCacheService;
use App\Services\Cache\OrganizationCache;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Fulfillment\AutoTripAssignmentService;
use App\Services\Notifications\CustomerNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Post-checkout work that must not block the cashier: stock ledger, journals,
 * customer SMS/email, trip assignment, cache invalidation, and deferred mobile AR.
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

        // Mobile edit/append may defer prior-sale stock reverse until here so confirm is instant.
        $priorSaleId = (int) (($sale->fulfillment_meta['supersedes_sale_id'] ?? 0));
        if ($priorSaleId > 0) {
            $priorSale = Sale::query()->find($priorSaleId);
            if ($priorSale) {
                $priorMeta = is_array($priorSale->fulfillment_meta) ? $priorSale->fulfillment_meta : [];
                if ($priorSale->stock_balanced || ! empty($priorMeta['stock_reverse_pending'])) {
                    try {
                        app(SaleInventoryRestorer::class)->reverseForPosEdit($priorSale, $user);
                        unset($priorMeta['stock_reverse_pending']);
                        $priorSale->update([
                            'stock_balanced' => 0,
                            'fulfillment_meta' => $priorMeta,
                        ]);
                    } catch (Throwable $e) {
                        Log::error('Deferred prior-sale stock reverse failed', [
                            'sale_id' => $sale->id,
                            'prior_sale_id' => $priorSaleId,
                            'error' => $e->getMessage(),
                        ]);
                        throw $e;
                    }
                }
            }
        }

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

        // Mobile AR must run even when runSideEffects is false (edge cases),
        // as long as checkout stamped mobile_deferred_ar on the sale.
        $this->ensureDeferredMobileAccountsReceivable($sale, $user, $gate);

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

        // Notifications / audit / trip assign are non-critical — queue when a real
        // queue driver is configured so cashiers are not blocked; otherwise run inline.
        if (config('queue.default') !== 'sync') {
            \App\Jobs\CheckoutSideEffectsJob::dispatch((int) $sale->id, (int) $user->id);
        } else {
            $this->runNonCriticalSideEffects($sale, $user);
        }

        try {
            app(MobileSalesService::class)->invalidateDashboardForUser($user);
            app(CompletedSalesCacheService::class)->invalidateForSale($sale);
            $orgId = (int) ($sale->organization_id ?: $user->organization_id);
            if ($orgId > 0) {
                OrganizationCache::invalidateReportsDashboard($orgId);
            }
        } catch (Throwable $e) {
            Log::warning('Deferred checkout cache invalidation failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function runNonCriticalSideEffects(Sale $sale, User $user): void
    {
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
    }

    /**
     * Mobile checkout skips inline invoice/loyalty so the HTTP response returns fast.
     * Finish AR settle + points here (afterResponse), then clear the flag.
     */
    public function ensureDeferredMobileAccountsReceivable(
        Sale $sale,
        User $user,
        CapabilityGate $gate,
    ): void {
        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
        if (empty($meta['mobile_deferred_ar'])) {
            return;
        }

        try {
            if (! in_array((string) $sale->status, ['held', 'draft', 'pending_approval', 'cancelled'], true)) {
                $sale = $sale->fresh(['payments']) ?? $sale;
                $invoiceService = app(CustomerInvoiceService::class);
                $invoice = $invoiceService->ensureForSale($sale, $user);
                if ($invoice) {
                    $invoice = $invoiceService->settleSaleTendersOntoInvoice($invoice, $sale, $user);
                    if (Schema::hasTable('mpesa_incoming_payments')) {
                        MpesaIncomingPayment::query()
                            ->where('applied_sale_id', $sale->id)
                            ->whereNull('applied_invoice_id')
                            ->update(['applied_invoice_id' => (int) $invoice->id]);
                    }
                }

                $this->awardLoyaltyPointsForCompletedSale(
                    (int) $sale->organization_id,
                    $sale->customer_num ? (int) $sale->customer_num : null,
                    $gate->moduleSettings('sales'),
                    (float) $sale->order_total,
                    $sale->loyalty_card_id ? (int) $sale->loyalty_card_id : null,
                );
            }
        } catch (Throwable $e) {
            Log::warning('Deferred mobile AR/loyalty failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }

        $fresh = $sale->fresh() ?? $sale;
        $meta = is_array($fresh->fulfillment_meta) ? $fresh->fulfillment_meta : [];
        unset($meta['mobile_deferred_ar']);
        $fresh->update(['fulfillment_meta' => $meta === [] ? null : $meta]);
    }

    protected function awardLoyaltyPointsForCompletedSale(
        int $organizationId,
        ?int $customerNum,
        array $salesSettings,
        float $orderTotal,
        ?int $preferredCardId = null,
    ): float {
        if (! $customerNum || empty($salesSettings['enable_redeemable_points'])) {
            return 0;
        }

        $kesPerPoint = max(0, (float) ($salesSettings['points_earn_per_kes'] ?? 1000));
        if ($kesPerPoint <= 0 || $orderTotal <= 0) {
            return 0;
        }

        $earned = floor($orderTotal / $kesPerPoint);
        if ($earned <= 0) {
            return 0;
        }

        return (float) DB::transaction(function () use (
            $organizationId,
            $customerNum,
            $preferredCardId,
            $earned,
        ) {
            $query = LoyaltyCard::where('organization_id', $organizationId)
                ->where('customer_num', $customerNum)
                ->where('is_active', true);

            if ($preferredCardId) {
                $query->where('id', $preferredCardId);
            }

            $card = $query->lockForUpdate()->first();
            if (! $card) {
                return 0.0;
            }

            $card->update([
                'points_balance' => (float) $card->points_balance + $earned,
            ]);

            return (float) $earned;
        });
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
        $items = ($sale->items ?? SaleItem::query()->where('sale_id', $sale->id)->get())
            ->sortBy(fn (SaleItem $item) => (string) $item->product_code)
            ->values();

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
        }, 5);
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
