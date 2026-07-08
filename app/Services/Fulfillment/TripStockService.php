<?php

namespace App\Services\Fulfillment;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\DispatchTrip;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Inventory\SaleStockLocationResolver;

class TripStockService
{
    use HandlesInventory;

    public function __construct(protected ErpContext $erp) {}

    public function deductTripStockIfNeeded(DispatchTrip $trip, User $user, string $when): void
    {
        if ($trip->stock_deducted_at && $when === 'trip_depart') {
            return;
        }

        $gate = $this->erp->gateForUser($user);
        $trip->loadMissing(['sales.items']);
        $deductedAny = false;

        foreach ($trip->sales as $sale) {
            if ($gate->stockDeductTiming((string) $sale->channel) !== $when) {
                continue;
            }

            $this->deductSaleStockIfNeeded($sale, $user);
            $deductedAny = true;
        }

        if ($deductedAny && $when === 'trip_depart') {
            $trip->update(['stock_deducted_at' => now()]);
        }
    }

    public function deductDeferredTripStockOnPickingComplete(DispatchTrip $trip, User $user): void
    {
        if ($trip->stock_deducted_at) {
            return;
        }

        $gate = $this->erp->gateForUser($user);
        $trip->loadMissing(['sales.items']);
        $deductedAny = false;

        foreach ($trip->sales as $sale) {
            if ($gate->stockDeductTiming((string) $sale->channel) !== 'trip_pick') {
                continue;
            }

            $this->deductSaleStockIfNeeded($sale, $user);
            $deductedAny = true;
        }

        if ($deductedAny) {
            $trip->update(['stock_deducted_at' => now()]);
        }
    }

    protected function deductSaleStockIfNeeded(Sale $sale, User $user): void
    {
        if ($sale->stock_balanced) {
            return;
        }

        $gate = $this->erp->gateForUser($user);
        $inventorySettings = $gate->moduleSettings('inventory');
        $salesSettings = $gate->moduleSettings('sales');
        $txnType = $this->saleTransactionType($sale->channel);
        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);

        $items = $sale->items ?? SaleItem::query()->where('sale_id', $sale->id)->get();
        foreach ($items as $item) {
            $product = Product::query()
                ->where('organization_id', $user->organization_id)
                ->where('product_code', $item->product_code)
                ->first();
            $location = $product
                ? SaleStockLocationResolver::forLine(
                    (string) $sale->channel,
                    $inventorySettings,
                    $salesSettings,
                    $product,
                    (bool) $item->on_wholesale_retail,
                )
                : SaleStockLocationResolver::forRouteFlag(
                    (string) $sale->channel,
                    $inventorySettings,
                    $salesSettings,
                    (bool) $item->on_wholesale_retail,
                );

            $this->postStockLedger($this->withProductUnitCost([
                'branch_id' => $sale->branch_id,
                'product_code' => $item->product_code,
                'stock_location' => $location,
                'transaction_type' => $txnType,
                'reference_type' => 'dispatch_trip',
                'reference_id' => $sale->id,
                'quantity_change' => -abs((float) $item->quantity),
                'created_by' => $user->id,
            ], (int) $user->organization_id), $allowBelowStock);
        }

        $sale->update(['stock_balanced' => 1]);
        \App\Models\StockReservation::query()
            ->where('sale_id', $sale->id)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);
    }
}
