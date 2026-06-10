<?php

namespace App\Http\Controllers\Api\V1\Operations\Concerns;

use App\Models\CurrentStock;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\SystemSetting;
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

        if ($change == 0.0) {
            throw new InvalidArgumentException('quantity_change cannot be zero.');
        }

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

    /**
     * When sales.retail_shop_wholesale_store_stock is enabled, retail lines use shop and wholesale lines use store.
     * When only shop or only store is enabled, all lines use that location.
     */
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

        return $this->saleStockLocation($channel, $inventorySettings);
    }

    /** Whether a line deducts from shop (retail) vs store (wholesale) when per-line routing is on. */
    protected function stockRouteAsRetail(Product $product, bool $onWholesaleRetailFlag, array $salesSettings): bool
    {
        if (! empty($salesSettings['retail_shop_wholesale_store_stock'])) {
            return $onWholesaleRetailFlag;
        }

        return $this->isRetailLine($product, $onWholesaleRetailFlag);
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
        bool $allowBelowStock = false,
        ?int $cartLineId = null,
    ): StockReservation {
        if (! $allowBelowStock) {
            $available = $this->stockNetAvailable($productCode, $branchId, $location);
            if ($quantity > $available) {
                throw new InvalidArgumentException(
                    "Cannot reserve {$quantity} of {$productCode} at {$location}; available {$available}."
                );
            }
        }

        return StockReservation::create([
            'branch_id' => $branchId,
            'product_code' => $productCode,
            'stock_location' => $location,
            'quantity' => $quantity,
            'cart_id' => $cartId,
            'cart_line_id' => $cartLineId,
            'reserved_by' => $userId,
        ]);
    }

    protected function releaseLineReservation(int $cartLineId): void
    {
        StockReservation::where('cart_line_id', $cartLineId)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);
    }

    protected function releaseCartReservations(int $cartId): void
    {
        StockReservation::where('cart_id', $cartId)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);
    }
}
