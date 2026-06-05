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
            $row = DB::table('current_stock')
                ->where('product_code', $productCode)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                $product = Product::where('product_code', $productCode)->first();
                $shopQty = (float) ($product->stock_in_shop ?? 0);
                $storeQty = (float) ($product->stock_in_store ?? 0);
                DB::table('current_stock')->insert([
                    'product_code' => $productCode,
                    'branch_id' => $branchId,
                    'shop_quantity' => $shopQty,
                    'store_quantity' => $storeQty,
                ]);
            } else {
                $product = Product::where('product_code', $productCode)->first();
                $shopQty = (float) ($row->shop_quantity ?? 0);
                $storeQty = (float) ($row->store_quantity ?? 0);
                if ($shopQty == 0.0 && ($product->stock_in_shop ?? 0) > 0) {
                    $shopQty = (float) $product->stock_in_shop;
                }
                if ($storeQty == 0.0 && ($product->stock_in_store ?? 0) > 0) {
                    $storeQty = (float) $product->stock_in_store;
                }
            }

            $before = $location === 'store' ? $storeQty : $shopQty;
            $after = $before + $change;

            if ($after < -0.0001) {
                throw new InvalidArgumentException("Insufficient stock at {$location} for {$productCode}.");
            }

            if ($location === 'store') {
                $storeQty = $after;
            } else {
                $shopQty = $after;
            }

            DB::table('current_stock')->updateOrInsert(
                ['product_code' => $productCode, 'branch_id' => $branchId],
                ['shop_quantity' => $shopQty, 'store_quantity' => $storeQty],
            );

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
