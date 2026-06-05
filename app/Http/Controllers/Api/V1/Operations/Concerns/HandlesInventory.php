<?php

namespace App\Http\Controllers\Api\V1\Operations\Concerns;

use App\Models\CurrentStock;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Shared stock ledger + reservation helpers for operations controllers.
 */
trait HandlesInventory
{
    protected function stockOnHand(string $productCode, int $branchId, string $location = 'shop'): float
    {
        $row = CurrentStock::where('product_code', $productCode)
            ->where('branch_id', $branchId)
            ->first();

        if (! $row) {
            return 0.0;
        }

        return $location === 'store'
            ? (float) $row->store_quantity
            : (float) $row->shop_quantity;
    }

    protected function stockReserved(string $productCode, int $branchId, string $location = 'shop'): float
    {
        return (float) DB::table('stock_reservations')
            ->where('product_code', $productCode)
            ->where('branch_id', $branchId)
            ->where('stock_location', $location)
            ->whereNull('released_at')
            ->sum('quantity');
    }

    protected function stockNetAvailable(string $productCode, int $branchId, string $location = 'shop'): float
    {
        return max(0, $this->stockOnHand($productCode, $branchId, $location)
            - $this->stockReserved($productCode, $branchId, $location));
    }

    protected function postStockLedger(array $data): InventoryTransaction
    {
        $branchId = (int) $data['branch_id'];
        $productCode = (string) $data['product_code'];
        $location = $data['stock_location'] ?? 'shop';
        $change = (float) $data['quantity_change'];

        if ($change == 0.0) {
            throw new InvalidArgumentException('quantity_change cannot be zero.');
        }

        return DB::transaction(function () use ($data, $branchId, $productCode, $location, $change) {
            $row = CurrentStock::query()
                ->where('product_code', $productCode)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                $row = new CurrentStock([
                    'product_code' => $productCode,
                    'branch_id' => $branchId,
                    'shop_quantity' => 0,
                    'store_quantity' => 0,
                ]);
            }

            $before = $location === 'store'
                ? (float) $row->store_quantity
                : (float) $row->shop_quantity;
            $after = $before + $change;

            if ($after < -0.0001) {
                throw new InvalidArgumentException("Insufficient stock at {$location} for {$productCode}.");
            }

            if ($location === 'store') {
                $row->store_quantity = $after;
            } else {
                $row->shop_quantity = $after;
            }
            $row->save();

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
                'unit_cost' => $data['unit_cost'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'],
            ]);

            $this->syncProductStockTotals($productCode, $branchId);

            return $txn;
        });
    }

    protected function syncProductStockTotals(string $productCode, int $branchId): void
    {
        $row = CurrentStock::where('product_code', $productCode)
            ->where('branch_id', $branchId)
            ->first();

        if (! $row) {
            return;
        }

        Product::where('product_code', $productCode)->update([
            'stock_in_shop' => $row->shop_quantity,
            'stock_in_store' => $row->store_quantity,
        ]);
    }

    protected function saleStockLocation(string $channel, array $settings = []): string
    {
        return match ($channel) {
            'backend', 'mobile' => $settings['default_distribution_sale_location'] ?? 'store',
            default => $settings['default_pos_sale_location'] ?? 'shop',
        };
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

    protected function reserveStock(
        int $branchId,
        string $productCode,
        float $quantity,
        string $location,
        int $userId,
        ?int $cartId = null,
    ): StockReservation {
        $available = $this->stockNetAvailable($productCode, $branchId, $location);
        if ($quantity > $available) {
            throw new InvalidArgumentException("Cannot reserve {$quantity} of {$productCode}; available {$available}.");
        }

        return StockReservation::create([
            'branch_id' => $branchId,
            'product_code' => $productCode,
            'stock_location' => $location,
            'quantity' => $quantity,
            'cart_id' => $cartId,
            'reserved_by' => $userId,
        ]);
    }

    protected function releaseCartReservations(int $cartId): void
    {
        StockReservation::where('cart_id', $cartId)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);
    }
}
