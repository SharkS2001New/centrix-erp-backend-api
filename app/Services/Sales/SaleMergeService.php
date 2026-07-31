<?php

namespace App\Services\Sales;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\ReferenceJournalReversalService;
use App\Services\Audit\AuditLogger;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Merge multiple mobile (field-sales) orders for the same customer into one survivor order.
 */
class SaleMergeService
{
    use HandlesInventory;

    public function __construct(
        protected ErpContext $erp,
        protected BackofficeOrderLineEditService $lineEdits,
    ) {}

    /**
     * @param  list<int>  $saleIds
     */
    public function merge(array $saleIds, User $user, ?CapabilityGate $gate = null, ?int $targetSaleId = null): Sale
    {
        $gate ??= $this->erp->gateForUser($user);
        $ids = array_values(array_unique(array_map('intval', $saleIds)));
        if (count($ids) < 2) {
            throw new InvalidArgumentException('Select at least two orders to merge.');
        }

        if (! $this->lineEdits->backofficeOrderEditEnabled($gate)) {
            throw new InvalidArgumentException('Order editing is disabled for this organization.');
        }

        return DB::transaction(function () use ($ids, $user, $gate, $targetSaleId) {
            $sales = Sale::query()
                ->with(['items', 'payments', 'dispatchTrips'])
                ->where('organization_id', $user->organization_id)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($sales->count() !== count($ids)) {
                throw new InvalidArgumentException('One or more selected orders were not found.');
            }

            foreach ($sales as $sale) {
                $this->assertMergeable($sale, $user, $gate);
            }

            $this->assertSameCustomerAndRoute($sales);

            $target = $this->resolveTarget($sales, $targetSaleId);
            $sources = $sales->filter(fn (Sale $sale) => (int) $sale->id !== (int) $target->id)->values();

            $sourceIds = $sources->map(fn (Sale $sale) => (int) $sale->id)->all();
            $sourceOrderNums = $sources->map(fn (Sale $sale) => (int) $sale->order_num)->all();

            foreach ($sources as $source) {
                $this->foldSourceIntoTarget($target, $source);
            }

            $target->refresh()->load(['items', 'payments']);
            if ($target->items->isEmpty()) {
                throw new InvalidArgumentException('Merged order must keep at least one line item.');
            }

            $this->recalculateTargetTotals($target);
            $this->stampTargetMergeMeta($target, $sourceIds, $sourceOrderNums, $user);

            foreach ($sources as $source) {
                $this->cancelMergedSource($source->fresh(), $user, $gate, (int) $target->id, (int) $target->order_num);
            }

            $fresh = $target->fresh(['items.product.unit', 'cashier:id,username,full_name', 'customer:customer_num,customer_name', 'payments']);
            app(CustomerInvoiceService::class)->ensureForSale(
                $fresh,
                $user,
                (float) $fresh->order_total,
                (float) $fresh->amount_paid,
            );

            app(AuditLogger::class)->log(
                $user,
                'merge',
                'sales',
                (int) $fresh->id,
                ['source_sale_ids' => $sourceIds, 'source_order_nums' => $sourceOrderNums],
                [
                    'order_total' => (float) $fresh->order_total,
                    'amount_paid' => (float) $fresh->amount_paid,
                    'line_count' => $fresh->items->count(),
                ],
            );

            $workflow = OrderWorkflowService::forGate($gate);
            if ($workflow->shouldHaveStockReserved(
                (string) $fresh->status,
                (string) ($fresh->channel ?: 'backend'),
            ) && ! $fresh->stock_balanced) {
                $this->syncSaleStockReservations($fresh, $user, $gate);
            }

            return $fresh->fresh(['items.product.unit', 'cashier:id,username,full_name', 'customer:customer_num,customer_name', 'payments']);
        });
    }

    public function canMergeSales(iterable $sales, User $user, CapabilityGate $gate): bool
    {
        try {
            $collection = collect($sales);
            if ($collection->count() < 2) {
                return false;
            }
            foreach ($collection as $sale) {
                $this->assertMergeable($sale, $user, $gate);
            }
            $this->assertSameCustomerAndRoute($collection);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    protected function assertMergeable(Sale $sale, User $user, CapabilityGate $gate): void
    {
        if ((int) $sale->organization_id !== (int) $user->organization_id) {
            throw new InvalidArgumentException('Orders must belong to your organization.');
        }

        if (! $this->isMobileOrder($sale)) {
            throw new InvalidArgumentException('Only mobile orders can be merged.');
        }

        if ($sale->status === 'cancelled' || (int) ($sale->archived ?? 0) === 1) {
            throw new InvalidArgumentException("Order #{$sale->order_num} cannot be merged.");
        }

        if ((bool) (($sale->fulfillment_meta ?? [])['legacy_import'] ?? false)) {
            throw new InvalidArgumentException("Legacy order #{$sale->order_num} cannot be merged.");
        }

        if (! empty(($sale->fulfillment_meta ?? [])['merged_into_sale_id'])) {
            throw new InvalidArgumentException("Order #{$sale->order_num} was already merged into another order.");
        }

        $workflow = OrderWorkflowService::forGate($gate);
        $channel = (string) ($sale->channel ?: 'mobile');
        if (! $workflow->isEditableLineStatus((string) $sale->status, $channel)) {
            throw new InvalidArgumentException(
                "Order #{$sale->order_num} can only be merged while booked, pending, or editable.",
            );
        }

        if ($sale->relationLoaded('dispatchTrips')
            ? $sale->dispatchTrips->isNotEmpty()
            : $sale->dispatchTrips()->exists()) {
            throw new InvalidArgumentException(
                "Order #{$sale->order_num} is on a dispatch trip and cannot be merged.",
            );
        }
    }

    /** @param  \Illuminate\Support\Collection<int, Sale>  $sales */
    protected function assertSameCustomerAndRoute($sales): void
    {
        $customerNums = $sales->map(fn (Sale $sale) => (int) ($sale->customer_num ?? 0))->unique()->values();
        if ($customerNums->count() !== 1 || (int) $customerNums->first() <= 0) {
            throw new InvalidArgumentException('Orders can only be merged when they belong to the same registered customer.');
        }

        $routeIds = $sales->map(fn (Sale $sale) => $sale->route_id !== null ? (int) $sale->route_id : null)->unique()->values();
        if ($routeIds->count() !== 1) {
            throw new InvalidArgumentException('Orders can only be merged when they are on the same route.');
        }
    }

    protected function isMobileOrder(Sale $sale): bool
    {
        $channel = strtolower((string) ($sale->channel ?: ''));
        $source = strtolower((string) ($sale->order_source ?: ''));

        return $channel === 'mobile' || $source === 'mobile';
    }

    /** @param  \Illuminate\Support\Collection<int, Sale>  $sales */
    protected function resolveTarget($sales, ?int $targetSaleId): Sale
    {
        if ($targetSaleId) {
            $target = $sales->get($targetSaleId);
            if (! $target) {
                throw new InvalidArgumentException('The keep-order target was not among the selected orders.');
            }

            return $target;
        }

        return $sales
            ->sortBy([
                fn (Sale $sale) => (int) $sale->order_num,
                fn (Sale $sale) => (int) $sale->id,
            ])
            ->first();
    }

    protected function foldSourceIntoTarget(Sale $target, Sale $source): void
    {
        $target->loadMissing('items');
        $itemsByKey = $target->items->keyBy(
            fn (SaleItem $item) => $item->product_code.'|'.(int) ($item->on_wholesale_retail ?? 0),
        );

        foreach ($source->items as $sourceItem) {
            $key = $sourceItem->product_code.'|'.(int) ($sourceItem->on_wholesale_retail ?? 0);
            /** @var SaleItem|null $existing */
            $existing = $itemsByKey->get($key);

            if ($existing) {
                $newQty = round((float) $existing->quantity + (float) $sourceItem->quantity, 4);
                $newAmount = round((float) $existing->amount + (float) $sourceItem->amount, 2);
                $newDiscount = round((float) ($existing->discount_given ?? 0) + (float) ($sourceItem->discount_given ?? 0), 2);
                $newVat = round((float) ($existing->product_vat ?? 0) + (float) ($sourceItem->product_vat ?? 0), 2);
                $unitPrice = $newQty > 0.0001 ? round($newAmount / $newQty, 4) : (float) $existing->selling_price;
                $display = (float) ($existing->display_unit_price ?? 0);
                $sourceDisplay = (float) ($sourceItem->display_unit_price ?? 0);
                if ($display <= 0 && $sourceDisplay > 0) {
                    $display = $sourceDisplay;
                } elseif ($display > 0 && $sourceDisplay > 0 && abs($display - $sourceDisplay) > 0.0001) {
                    // Keep survivor display price when tiers differ; amount already summed.
                } elseif ($display <= 0) {
                    $display = $unitPrice;
                }

                $existing->update([
                    'quantity' => $newQty,
                    'amount' => $newAmount,
                    'discount_given' => $newDiscount,
                    'product_vat' => $newVat,
                    'selling_price' => $unitPrice,
                    'display_unit_price' => $display > 0 ? $display : $unitPrice,
                ]);
                $sourceItem->delete();
            } else {
                $nextLine = ((int) $target->items()->max('line_no')) + 1;
                $sourceItem->update([
                    'sale_id' => $target->id,
                    'line_no' => $nextLine,
                    'item_code' => (string) $nextLine,
                ]);
                $itemsByKey->put($key, $sourceItem->fresh());
                $target->unsetRelation('items');
                $target->load('items');
                $itemsByKey = $target->items->keyBy(
                    fn (SaleItem $item) => $item->product_code.'|'.(int) ($item->on_wholesale_retail ?? 0),
                );
            }
        }

        SalePayment::query()
            ->where('sale_id', $source->id)
            ->update(['sale_id' => $target->id]);

        $target->update([
            'cash' => round((float) ($target->cash ?? 0) + (float) ($source->cash ?? 0), 2),
            'mpesa_amount' => round((float) ($target->mpesa_amount ?? 0) + (float) ($source->mpesa_amount ?? 0), 2),
            'equity_amount' => round((float) ($target->equity_amount ?? 0) + (float) ($source->equity_amount ?? 0), 2),
            'kcb_amount' => round((float) ($target->kcb_amount ?? 0) + (float) ($source->kcb_amount ?? 0), 2),
            'voucher_payment_amount' => round((float) ($target->voucher_payment_amount ?? 0) + (float) ($source->voucher_payment_amount ?? 0), 2),
            'points_payment_amount' => round((float) ($target->points_payment_amount ?? 0) + (float) ($source->points_payment_amount ?? 0), 2),
            'amount_paid' => round((float) ($target->amount_paid ?? 0) + (float) ($source->amount_paid ?? 0), 2),
            'order_discount' => round((float) ($target->order_discount ?? 0) + (float) ($source->order_discount ?? 0), 2),
            'stock_balanced' => ((int) ($target->stock_balanced ?? 0) || (int) ($source->stock_balanced ?? 0)) ? 1 : 0,
            'delivery_date' => $target->delivery_date ?? $source->delivery_date,
            'required_date' => $target->required_date ?? $source->required_date,
        ]);
    }

    protected function recalculateTargetTotals(Sale $target): void
    {
        $target->load('items');
        $lineGross = round((float) $target->items->sum('amount'), 2);
        $lineVat = round((float) $target->items->sum('product_vat'), 2);
        $orderDiscount = min(max(0, (float) ($target->order_discount ?? 0)), $lineGross);
        $scaled = CentrixSalesScope::scaleVatForOrderDiscount($lineGross, $lineVat, $orderDiscount);
        $orderTotal = $scaled['order_total'];
        $totalVat = $scaled['total_vat'];

        $paymentRowsTotal = round((float) $target->payments()->sum('amount'), 2);
        $amountPaid = $paymentRowsTotal > 0.01
            ? $paymentRowsTotal
            : round((float) ($target->amount_paid ?? 0), 2);
        $amountPaid = min($amountPaid, $orderTotal);

        $target->update([
            'order_total' => $orderTotal,
            'total_vat' => $totalVat,
            'order_discount' => $scaled['order_discount'],
            'amount_paid' => $amountPaid,
            'payment_status' => $this->derivePaymentStatus($orderTotal, $amountPaid),
        ]);
    }

    /**
     * @param  list<int>  $sourceIds
     * @param  list<int>  $sourceOrderNums
     */
    protected function stampTargetMergeMeta(Sale $target, array $sourceIds, array $sourceOrderNums, User $user): void
    {
        $meta = is_array($target->fulfillment_meta) ? $target->fulfillment_meta : [];
        $history = is_array($meta['merged_from'] ?? null) ? $meta['merged_from'] : [];
        $history[] = [
            'sale_ids' => $sourceIds,
            'order_nums' => $sourceOrderNums,
            'merged_at' => now()->toIso8601String(),
            'merged_by' => $user->id,
        ];
        $meta['merged_from'] = $history;
        $meta['merged_from_sale_ids'] = array_values(array_unique(array_merge(
            array_map('intval', $meta['merged_from_sale_ids'] ?? []),
            $sourceIds,
        )));

        $target->update(['fulfillment_meta' => $meta]);
    }

    protected function cancelMergedSource(
        Sale $sale,
        User $user,
        CapabilityGate $gate,
        int $targetSaleId,
        int $targetOrderNum,
    ): void {
        $from = (string) $sale->status;
        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
        $meta['status_before_cancel'] = $from;
        $meta['merged_into_sale_id'] = $targetSaleId;
        $meta['merged_into_order_num'] = $targetOrderNum;
        $meta['merged_at'] = now()->toIso8601String();
        $meta['merged_by'] = $user->id;

        // Items/payments already moved — do not restore stock ledgers.
        $this->releaseSaleReservations((int) $sale->id);

        $sale->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'stock_balanced' => 0,
            'order_total' => 0,
            'total_vat' => 0,
            'amount_paid' => 0,
            'cash' => 0,
            'mpesa_amount' => 0,
            'equity_amount' => 0,
            'kcb_amount' => 0,
            'payment_status' => 'unpaid',
            'fulfillment_meta' => $meta,
            'comments' => trim(($sale->comments ? $sale->comments.' · ' : '')."Merged into order #{$targetOrderNum}"),
        ]);

        app(CustomerInvoiceService::class)->voidForCancelledSale($sale->fresh(), $user);
        app(ReferenceJournalReversalService::class)->reverseIfEnabled('sale', (int) $sale->id, $user, $gate);
        app(ActionRequestService::class)->cancelAllPendingForSale(
            $sale->fresh(),
            $user,
            "Order was merged into #{$targetOrderNum}.",
        );
        app(AuditLogger::class)->log(
            $user,
            'merge_cancel',
            'sales',
            (int) $sale->id,
            ['status' => $from],
            ['status' => 'cancelled', 'merged_into_sale_id' => $targetSaleId],
        );
    }

    protected function derivePaymentStatus(float $total, float $paid): string
    {
        if ($total <= 0.01) {
            return 'paid';
        }
        if ($paid <= 0.01) {
            return 'unpaid';
        }
        if ($paid + 0.01 >= $total) {
            return 'paid';
        }

        return 'partially_paid';
    }
}
