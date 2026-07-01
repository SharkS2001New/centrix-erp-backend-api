<?php

namespace App\Services\Fulfillment;

use App\Models\CurrentStock;
use App\Models\DispatchTrip;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Erp\ErpContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TripStockService
{
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
            $location = $this->saleLineStockLocation(
                $sale->channel,
                $inventorySettings,
                $salesSettings,
                (bool) $item->on_wholesale_retail,
            );

            $this->postStockLedger([
                'branch_id' => $sale->branch_id,
                'product_code' => $item->product_code,
                'stock_location' => $location,
                'transaction_type' => $txnType,
                'reference_type' => 'dispatch_trip',
                'reference_id' => $sale->id,
                'quantity_change' => -abs((float) $item->quantity),
                'created_by' => $user->id,
            ], $allowBelowStock);
        }

        $sale->update(['stock_balanced' => 1]);
        \App\Models\StockReservation::query()
            ->where('sale_id', $sale->id)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);
    }

    protected function saleTransactionType(string $channel): string
    {
        return match ($channel) {
            'pos' => 'POS_SALE',
            'mobile' => 'MOBILE_SALE',
            'backend' => 'BACKEND_SALE',
            default => 'POS_SALE',
        };
    }

    protected function saleLineStockLocation(
        string $channel,
        array $inventorySettings,
        array $salesSettings,
        bool $isRetailLine,
    ): string {
        if (! empty($salesSettings['retail_shop_wholesale_store_stock'])) {
            return $isRetailLine ? 'shop' : 'store';
        }
        if (! empty($salesSettings['allow_sell_from_shop']) && empty($salesSettings['allow_sell_from_store'])) {
            return 'shop';
        }
        if (empty($salesSettings['allow_sell_from_shop']) && ! empty($salesSettings['allow_sell_from_store'])) {
            return 'store';
        }

        return match ($channel) {
            'backend', 'mobile' => $inventorySettings['default_distribution_sale_location'] ?? 'store',
            default => $inventorySettings['default_pos_sale_location'] ?? 'shop',
        };
    }

    protected function organizationAllowsBelowStock(?int $organizationId): bool
    {
        if (! $organizationId) {
            return false;
        }

        $system = SystemSetting::query()
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->first();

        return (bool) ($system?->allow_below_stock ?? false);
    }

    protected function postStockLedger(array $data, bool $allowBelowStock = false): InventoryTransaction
    {
        $branchId = (int) $data['branch_id'];
        $productCode = (string) $data['product_code'];
        $location = $data['stock_location'] ?? 'shop';
        $change = (float) $data['quantity_change'];

        $before = $this->stockOnHand($productCode, $branchId, $location);
        $after = $before + $change;

        if (! $allowBelowStock && $after < -0.0001) {
            throw new InvalidArgumentException("Insufficient stock at {$location} for {$productCode}.");
        }

        return DB::transaction(function () use ($data, $branchId, $productCode, $location, $change, $before, $after) {
            $txn = InventoryTransaction::create([
                'branch_id' => $branchId,
                'product_code' => $productCode,
                'stock_location' => $location,
                'transaction_type' => $data['transaction_type'],
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'quantity_change' => $change,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'created_by' => $data['created_by'],
            ]);

            $row = CurrentStock::query()
                ->where('product_code', $productCode)
                ->where('branch_id', $branchId)
                ->first();
            if ($row) {
                Product::query()->where('product_code', $productCode)->update([
                    'stock_in_shop' => $row->shop_quantity,
                    'stock_in_store' => $row->store_quantity,
                ]);
            }

            return $txn;
        });
    }

    protected function stockOnHand(string $productCode, int $branchId, string $location = 'shop'): float
    {
        $row = CurrentStock::query()
            ->where('product_code', $productCode)
            ->where('branch_id', $branchId)
            ->first();

        if (! $row) {
            return 0.0;
        }

        return $location === 'store'
            ? (float) $row->store_quantity
            : (float) $row->shop_quantity;
    }
}
