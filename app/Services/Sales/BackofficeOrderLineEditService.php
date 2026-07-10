<?php

namespace App\Services\Sales;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesPricing;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Kra\SalesVatCalculator;
use App\Services\Sales\DiscountApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BackofficeOrderLineEditService
{
    use HandlesInventory;
    use HandlesPricing;

    public function __construct(
        protected PosLinePricingService $pricing,
        protected DiscountApprovalService $discounts,
    ) {}

    public function isBackofficeOrder(Sale $sale, ?CapabilityGate $gate = null): bool
    {
        if ((string) $sale->status === 'editable') {
            return true;
        }

        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
        if (($meta['sales_workspace'] ?? null) === 'backoffice') {
            return true;
        }

        $source = strtolower((string) ($sale->order_source ?? ''));
        $channel = strtolower((string) ($sale->channel ?: ''));

        if ($channel === 'mobile' || $source === 'mobile') {
            return true;
        }

        if ($gate !== null && ! $gate->enabled('sales.pos')) {
            if (in_array($source, ['pos', 'backend', 'backoffice', 'erp'], true)) {
                return true;
            }
            if (in_array($channel, ['pos', 'backend', 'backoffice', 'erp'], true)) {
                return true;
            }
        }

        if (in_array($source, ['pos'], true) || $channel === 'pos') {
            return false;
        }

        if (in_array($source, ['backoffice', 'backend'], true)) {
            return true;
        }

        if ($channel === 'backend') {
            return true;
        }

        return ($meta['sales_workspace'] ?? null) === 'backoffice';
    }

    public function backofficeOrderEditEnabled(CapabilityGate $gate): bool
    {
        if (! $gate->enabled('sales')) {
            return false;
        }

        return (bool) ($gate->moduleSettings('sales')['enable_backoffice_order_edit'] ?? true);
    }

    public function canEditLineQuantities(Sale $sale, User $user, CapabilityGate $gate): bool
    {
        try {
            $this->assertLineEditAllowed($sale, $user, $gate);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function assertLineEditAllowed(Sale $sale, User $user, CapabilityGate $gate): void
    {
        if (! $this->backofficeOrderEditEnabled($gate)) {
            throw new InvalidArgumentException('Backoffice order editing is disabled for this organization.');
        }

        if (! $this->isBackofficeOrder($sale, $gate)) {
            throw new InvalidArgumentException('This order does not support line edits from Sales.');
        }

        if ($sale->status === 'cancelled' || (int) ($sale->archived ?? 0) === 1) {
            throw new InvalidArgumentException('This order cannot be edited.');
        }

        if ((bool) (($sale->fulfillment_meta ?? [])['legacy_import'] ?? false)) {
            throw new InvalidArgumentException('Legacy orders cannot be edited.');
        }

        $workflow = OrderWorkflowService::forGate($gate);
        $channel = (string) ($sale->channel ?: 'backend');
        if (! $workflow->isEditableLineStatus((string) $sale->status, $channel)) {
            throw new InvalidArgumentException('Orders can only be edited while booked, pending, or editable.');
        }

        if ((string) $sale->status === 'editable') {
            $this->assertDiscountRevisionOwner($sale, $user);
        }
    }

    protected function assertDiscountRevisionOwner(Sale $sale, User $user): void
    {
        if ((int) $sale->cashier_id !== (int) $user->id && ! $user->is_admin) {
            throw new InvalidArgumentException('You can only revise orders you submitted for approval.');
        }
    }

    public function allowsLineDiscountEdit(CapabilityGate $gate, ?Sale $sale = null): bool
    {
        $salesSettings = $gate->moduleSettings('sales');

        // Editable / pending-approval revisions must keep discount edits when either
        // channel's approval workflow is on (e.g. mobile-only approval, backoffice edit).
        if ($sale !== null) {
            $status = (string) $sale->status;
            if (in_array($status, ['editable', 'pending_approval'], true)
                && $this->discounts->discountApprovalEnabled($salesSettings)) {
                return true;
            }
        }

        return $this->discounts->allowsManualLineDiscount($salesSettings, 'backend')
            || $this->discounts->discountApprovalEnabled($salesSettings, 'backend');
    }

    /**
     * @param  list<array{id: int, quantity: float|int|string, discount_given?: float|int|string|null}>  $items
     */
    public function updateLineQuantities(Sale $sale, User $user, array $items, CapabilityGate $gate): Sale
    {
        $this->assertLineEditAllowed($sale, $user, $gate);
        $wasEditable = (string) $sale->status === 'editable';
        $allowDiscountEdit = $this->allowsLineDiscountEdit($gate, $sale);

        return DB::transaction(function () use ($sale, $user, $items, $gate, $wasEditable, $allowDiscountEdit) {
            $sale = Sale::with('items')->lockForUpdate()->findOrFail($sale->id);
            $itemsById = $sale->items->keyBy('id');
            $salesSettings = $gate->moduleSettings('sales');
            $inventorySettings = $gate->moduleSettings('inventory');
            $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);
            $qtyChanged = false;
            $lineChanged = false;

            foreach ($items as $row) {
                $itemId = (int) $row['id'];
                $newQty = round(max(0, (float) $row['quantity']), 4);
                /** @var SaleItem|null $saleItem */
                $saleItem = $itemsById->get($itemId);
                if (! $saleItem) {
                    throw new InvalidArgumentException("Line item [{$itemId}] was not found on this order.");
                }

                $oldQty = (float) $saleItem->quantity;
                $newDiscount = array_key_exists('discount_given', $row)
                    ? max(0, (float) $row['discount_given'])
                    : (float) ($saleItem->discount_given ?? 0);

                if (! $allowDiscountEdit && array_key_exists('discount_given', $row)) {
                    $newDiscount = (float) ($saleItem->discount_given ?? 0);
                }

                if ($newQty <= 0) {
                    throw new InvalidArgumentException('Quantity must be greater than zero.');
                }

                $itemQtyChanged = abs($newQty - $oldQty) >= 0.0001;
                $discountChanged = abs($newDiscount - (float) ($saleItem->discount_given ?? 0)) >= 0.01;

                if (! $itemQtyChanged && ! $discountChanged) {
                    continue;
                }

                $lineChanged = true;
                $qtyChanged = $qtyChanged || $itemQtyChanged;

                if ($discountChanged && ! $itemQtyChanged) {
                    $product = Product::query()->find($saleItem->product_code);
                    if (! $product) {
                        throw new InvalidArgumentException("Product [{$saleItem->product_code}] was not found.");
                    }

                    $isRetail = (bool) $saleItem->on_wholesale_retail;
                    [$unitPrice, $amount] = $this->pricing->resolveLineAmounts(
                        $product,
                        $newQty,
                        $isRetail,
                        $newDiscount,
                        $sale->route_id ? (int) $sale->route_id : null,
                        (float) $saleItem->selling_price,
                        false,
                    );
                    $product->loadMissing('vat');
                    $productVat = SalesVatCalculator::vatFromInclusiveGross(
                        max(0, $amount),
                        SalesVatCalculator::vatRateFromProduct($product),
                    );

                    $saleItem->update([
                        'quantity' => $newQty,
                        'selling_price' => $unitPrice,
                        'amount' => $amount,
                        'product_vat' => $productVat,
                        'discount_given' => $newDiscount,
                    ]);
                } else {
                    $saleItem->update([
                        'quantity' => $newQty,
                        'amount' => $this->scaleByQtyRatio((float) $saleItem->amount, $newQty, $oldQty),
                        'product_vat' => $this->scaleByQtyRatio((float) ($saleItem->product_vat ?? 0), $newQty, $oldQty),
                        'discount_given' => $discountChanged
                            ? $newDiscount
                            : $this->scaleByQtyRatio((float) ($saleItem->discount_given ?? 0), $newQty, $oldQty),
                    ]);
                }

                if ($sale->stock_balanced && $itemQtyChanged) {
                    $this->adjustStockForQtyChange($sale, $saleItem, $oldQty, $newQty, $user, $gate, $salesSettings, $inventorySettings, $allowBelowStock);
                }
            }

            $sale->refresh()->load('items');
            $orderTotal = round((float) $sale->items->sum('amount'), 2);
            $totalVat = round((float) $sale->items->sum('product_vat'), 2);
            $amountPaid = min((float) ($sale->amount_paid ?? 0), $orderTotal);

            $updates = [
                'order_total' => $orderTotal,
                'total_vat' => $totalVat,
                'amount_paid' => $amountPaid,
                'payment_status' => $this->derivePaymentStatus($orderTotal, $amountPaid),
            ];

            if ($wasEditable && $lineChanged) {
                $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
                $approval = is_array($meta['discount_approval'] ?? null) ? $meta['discount_approval'] : [];
                $advisedApplied = $this->discounts->saleMatchesApproverGuidance($sale->fresh(['items']));
                if ($advisedApplied) {
                    $approval['advised_discount_applied'] = true;
                }
                unset($approval['rejected_at'], $approval['rejected_by'], $approval['rejection_reason'], $approval['rejection_guidance_type'], $approval['advised_discount_amount'], $approval['advised_discount_lines']);
                $meta['discount_approval'] = $approval;

                $gate = app(ErpContext::class)->gateForUser($user);
                $updates['status'] = $this->discounts->requiresDiscountResubmitApproval($sale, $user, $gate)
                    ? 'pending_approval'
                    : 'booked';
                $updates['fulfillment_meta'] = $meta;
            }

            $sale->update($updates);

            if (($updates['status'] ?? null) === 'booked') {
                app(CustomerInvoiceService::class)->ensureForSale(
                    $sale->fresh(),
                    $user,
                    $orderTotal,
                    $amountPaid,
                );
            } elseif (($updates['status'] ?? null) === 'pending_approval' && $wasEditable) {
                $gate = app(ErpContext::class)->gateForUser($user);
                $request = $this->discounts->resubmitSaleForApproval(
                    $sale->fresh(['items']),
                    $user,
                    $gate,
                    fromEditableSave: true,
                );
                if ($request === null) {
                    throw ValidationException::withMessages([
                        'discount_approval' => 'Could not submit this order for discount approval.',
                    ]);
                }
            }

            if ($qtyChanged && ! $sale->stock_balanced) {
                $workflow = app(OrderWorkflowService::class);
                if ($workflow->shouldHaveStockReserved(
                    (string) $sale->status,
                    (string) ($sale->channel ?: 'backend'),
                )) {
                    $this->syncSaleStockReservations($sale->fresh(['items']), $user, $gate);
                }
            }

            return $sale->fresh(['items.product.unit', 'cashier:id,username,full_name', 'customer:customer_num,customer_name']);
        });
    }

    protected function adjustStockForQtyChange(
        Sale $sale,
        SaleItem $saleItem,
        float $oldQty,
        float $newQty,
        User $user,
        CapabilityGate $gate,
        array $salesSettings,
        array $inventorySettings,
        bool $allowBelowStock,
    ): void {
        $delta = $newQty - $oldQty;
        if (abs($delta) < 0.0001) {
            return;
        }

        $product = Product::query()
            ->where('organization_id', $user->organization_id)
            ->where('product_code', $saleItem->product_code)
            ->first();
        if (! $product) {
            return;
        }

        $isRetailLine = (bool) $saleItem->on_wholesale_retail;
        $location = $this->resolveSaleLineStockLocation(
            (string) ($sale->channel ?: 'backend'),
            $inventorySettings,
            $salesSettings,
            $product,
            $isRetailLine,
        );

        $unitCost = max(0, (float) ($product->last_cost_price ?? 0));

        $this->postStockLedger([
            'branch_id' => (int) $sale->branch_id,
            'product_code' => (string) $saleItem->product_code,
            'stock_location' => $location,
            'transaction_type' => $delta > 0 ? $this->saleTransactionType((string) ($sale->channel ?: 'backend')) : 'RETURN',
            'reference_type' => 'sale_line_edit',
            'reference_id' => $sale->id,
            'quantity_change' => $delta > 0 ? -abs($delta) : abs($delta),
            'unit_cost' => $unitCost > 0 ? $unitCost : null,
            'notes' => 'Backoffice order line quantity edit',
            'created_by' => $user->id,
        ], $allowBelowStock);
    }

    protected function scaleByQtyRatio(float $value, float $newQty, float $oldQty): float
    {
        if ($oldQty <= 0) {
            return 0.0;
        }

        return round($value * ($newQty / $oldQty), 2);
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

        return 'partial';
    }
}
