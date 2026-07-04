<?php

namespace App\Services\Sales;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesPricing;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\OrderWorkflowService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BackofficeOrderLineEditService
{
    use HandlesInventory;
    use HandlesPricing;

    public function isBackofficeOrder(Sale $sale): bool
    {
        $source = strtolower((string) ($sale->order_source ?? ''));
        if (in_array($source, ['pos', 'mobile'], true)) {
            return false;
        }

        if (in_array($source, ['backoffice', 'backend'], true)) {
            return true;
        }

        if (strtolower((string) ($sale->channel ?: '')) === 'backend') {
            return true;
        }

        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];

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

        if (! $this->isBackofficeOrder($sale)) {
            throw new InvalidArgumentException('Only backoffice orders support quantity edits.');
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
            throw new InvalidArgumentException('Orders can only be edited while booked or pending.');
        }
    }

    /**
     * @param  list<array{id: int, quantity: float|int|string}>  $items
     */
    public function updateLineQuantities(Sale $sale, User $user, array $items, CapabilityGate $gate): Sale
    {
        $this->assertLineEditAllowed($sale, $user, $gate);

        return DB::transaction(function () use ($sale, $user, $items, $gate) {
            $sale = Sale::with('items')->lockForUpdate()->findOrFail($sale->id);
            $itemsById = $sale->items->keyBy('id');
            $salesSettings = $gate->moduleSettings('sales');
            $inventorySettings = $gate->moduleSettings('inventory');
            $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);
            $qtyChanged = false;

            foreach ($items as $row) {
                $itemId = (int) $row['id'];
                $newQty = round(max(0, (float) $row['quantity']), 4);
                /** @var SaleItem|null $saleItem */
                $saleItem = $itemsById->get($itemId);
                if (! $saleItem) {
                    throw new InvalidArgumentException("Line item [{$itemId}] was not found on this order.");
                }

                $oldQty = (float) $saleItem->quantity;
                if (abs($newQty - $oldQty) < 0.0001) {
                    continue;
                }

                $qtyChanged = true;

                if ($newQty <= 0) {
                    throw new InvalidArgumentException('Quantity must be greater than zero.');
                }

                $saleItem->update([
                    'quantity' => $newQty,
                    'amount' => $this->scaleByQtyRatio((float) $saleItem->amount, $newQty, $oldQty),
                    'product_vat' => $this->scaleByQtyRatio((float) ($saleItem->product_vat ?? 0), $newQty, $oldQty),
                    'discount_given' => $this->scaleByQtyRatio((float) ($saleItem->discount_given ?? 0), $newQty, $oldQty),
                ]);

                if ($sale->stock_balanced) {
                    $this->adjustStockForQtyChange($sale, $saleItem, $oldQty, $newQty, $user, $gate, $salesSettings, $inventorySettings, $allowBelowStock);
                }
            }

            $sale->refresh()->load('items');
            $orderTotal = round((float) $sale->items->sum('amount'), 2);
            $totalVat = round((float) $sale->items->sum('product_vat'), 2);
            $amountPaid = min((float) ($sale->amount_paid ?? 0), $orderTotal);

            $sale->update([
                'order_total' => $orderTotal,
                'total_vat' => $totalVat,
                'amount_paid' => $amountPaid,
                'payment_status' => $this->derivePaymentStatus($orderTotal, $amountPaid),
            ]);

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

        $product = Product::query()->find($saleItem->product_code);
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
