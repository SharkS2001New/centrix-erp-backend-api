<?php

namespace App\Http\Controllers\Api\V1\Operations\Concerns;

use App\Models\CurrentStock;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\SystemSetting;
use App\Services\Inventory\SaleStockLocationResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Shared stock ledger + reservation helpers for operations controllers.
 */
trait HandlesInventory
{
    /** @var array<int, bool> */
    protected array $allowBelowStockCache = [];

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
        return (float) $this->activeStockReservationQuery()
            ->where('product_code', $productCode)
            ->where('branch_id', $branchId)
            ->where('stock_location', $location)
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

        if (array_key_exists($organizationId, $this->allowBelowStockCache)) {
            return $this->allowBelowStockCache[$organizationId];
        }

        $system = SystemSetting::query()
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->first();

        return $this->allowBelowStockCache[$organizationId] = (bool) ($system?->allow_below_stock ?? false);
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

    protected function reverseSaleStockDeductions(Sale $sale, User $user): void
    {
        if (! $sale->stock_balanced) {
            return;
        }

        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);
        $txns = InventoryTransaction::query()
            ->where('quantity_change', '<', 0)
            ->where(function ($query) use ($sale) {
                $query->where(function ($sub) use ($sale) {
                    $sub->where('reference_type', 'sale')
                        ->where('reference_id', $sale->id);
                })->orWhere(function ($sub) use ($sale) {
                    $sub->where('reference_type', 'dispatch_trip')
                        ->where('reference_id', $sale->id);
                });
            })
            ->get();

        foreach ($txns as $txn) {
            $this->postStockLedger([
                'branch_id' => (int) $txn->branch_id,
                'product_code' => (string) $txn->product_code,
                'stock_location' => (string) $txn->stock_location,
                'transaction_type' => 'RETURN',
                'reference_type' => 'sale_cancel',
                'reference_id' => $sale->id,
                'quantity_change' => abs((float) $txn->quantity_change),
                'unit_cost' => $txn->unit_cost,
                'notes' => 'Sale restored to cart for editing',
                'created_by' => $user->id,
            ], $allowBelowStock);
        }

        $sale->stock_balanced = 0;
    }

    protected function saleStockLocation(string $channel, array $settings = []): string
    {
        return match ($channel) {
            'backend', 'mobile' => $settings['default_distribution_sale_location'] ?? 'store',
            default => $settings['default_pos_sale_location'] ?? 'shop',
        };
    }

    protected function saleLineStockLocation(
        string $channel,
        array $inventorySettings,
        array $salesSettings,
        bool $stockAsRetail,
    ): string {
        return SaleStockLocationResolver::forRouteFlag(
            $channel,
            $inventorySettings,
            $salesSettings,
            $stockAsRetail,
        );
    }

    protected function resolveSaleLineStockLocation(
        string $channel,
        array $inventorySettings,
        array $salesSettings,
        Product $product,
        bool $onWholesaleRetailFlag,
    ): string {
        return SaleStockLocationResolver::forLine(
            $channel,
            $inventorySettings,
            $salesSettings,
            $product,
            $onWholesaleRetailFlag,
        );
    }

    /** Whether a line deducts from shop (retail) vs store (wholesale) when per-line routing is on. */
    protected function stockRouteAsRetail(Product $product, bool $onWholesaleRetailFlag, array $salesSettings): bool
    {
        return SaleStockLocationResolver::stockRouteAsRetail($product, $onWholesaleRetailFlag, $salesSettings);
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
        ?\Illuminate\Support\Carbon $expiresAt = null,
        ?int $saleId = null,
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
            'sale_id' => $saleId,
            'reserved_by' => $userId,
            'expires_at' => $saleId ? null : ($expiresAt ?? $this->reservationExpiresAt($userId)),
        ]);
    }

    protected function reservationExpiresAtForUser(User $user, array $inventorySettings): ?\Illuminate\Support\Carbon
    {
        $ttl = $this->cartReservationTtlMinutes($inventorySettings);

        return $ttl > 0 ? now()->addMinutes($ttl) : null;
    }

    protected function reservationExpiresAt(int $userId): ?\Illuminate\Support\Carbon
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return null;
        }

        $ttl = $this->cartReservationTtlMinutes(
            app(\App\Services\Erp\ErpContext::class)->gateForUser($user)->moduleSettings('inventory'),
        );

        return $ttl > 0 ? now()->addMinutes($ttl) : null;
    }

    protected function cartReservationTtlMinutes(array $inventorySettings): int
    {
        if (array_key_exists('cart_reservation_ttl_minutes', $inventorySettings)) {
            return min(15, max(0, (int) $inventorySettings['cart_reservation_ttl_minutes']));
        }

        return min(15, max(0, (int) config('erp.module_settings_defaults.inventory.cart_reservation_ttl_minutes', 15)));
    }

    protected function activeStockReservationQuery()
    {
        return DB::table('stock_reservations')
            ->whereNull('released_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /** Release expired reservations so abandoned carts stop blocking stock. */
    protected function releaseExpiredReservations(?int $cartId = null): int
    {
        $query = StockReservation::query()
            ->whereNull('released_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());

        if ($cartId !== null) {
            $query->where('cart_id', $cartId);
        }

        return $query->update(['released_at' => now()]);
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

    protected function transferCartReservationsToSale(int $cartId, int $saleId): void
    {
        StockReservation::where('cart_id', $cartId)
            ->whereNull('released_at')
            ->update([
                'sale_id' => $saleId,
                'cart_id' => null,
                'cart_line_id' => null,
                'expires_at' => null,
            ]);
    }

    protected function transferSaleReservationsToCart(int $saleId, int $cartId): void
    {
        StockReservation::where('sale_id', $saleId)
            ->whereNull('released_at')
            ->update([
                'sale_id' => null,
                'cart_id' => $cartId,
                'expires_at' => null,
            ]);
    }

    protected function releaseSaleReservations(int $saleId): void
    {
        StockReservation::where('sale_id', $saleId)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);
    }

    protected function saleHasActiveReservations(int $saleId): bool
    {
        return StockReservation::query()
            ->where('sale_id', $saleId)
            ->whereNull('released_at')
            ->exists();
    }

    protected function restoreCancelledSaleStock(Sale $sale, User $user): void
    {
        $this->releaseSaleReservations((int) $sale->id);
        $this->reverseSaleStockDeductions($sale, $user);
    }

    protected function reserveSaleStockIfNeeded(Sale $sale, User $user, \App\Services\Erp\CapabilityGate $gate): void
    {
        if ($sale->stock_balanced || $this->saleHasActiveReservations((int) $sale->id)) {
            return;
        }

        $this->reserveAllSaleItemStock($sale, $user, $gate);
    }

    protected function syncSaleStockReservations(Sale $sale, User $user, \App\Services\Erp\CapabilityGate $gate): void
    {
        if ($sale->stock_balanced) {
            return;
        }

        $this->releaseSaleReservations((int) $sale->id);
        $this->reserveAllSaleItemStock($sale->fresh(['items']), $user, $gate);
    }

    protected function reserveAllSaleItemStock(Sale $sale, User $user, \App\Services\Erp\CapabilityGate $gate): void
    {
        if ($sale->stock_balanced) {
            return;
        }

        $inventorySettings = $gate->moduleSettings('inventory');
        $salesSettings = $gate->moduleSettings('sales');
        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);
        $items = $sale->items ?? SaleItem::query()->where('sale_id', $sale->id)->get();

        foreach ($items as $item) {
            $product = Product::query()->find($item->product_code);
            if (! $product) {
                continue;
            }

            $location = $this->resolveSaleLineStockLocation(
                (string) $sale->channel,
                $inventorySettings,
                $salesSettings,
                $product,
                (bool) $item->on_wholesale_retail,
            );

            $this->reserveStock(
                (int) $sale->branch_id,
                (string) $item->product_code,
                (float) $item->quantity,
                $location,
                (int) $user->id,
                null,
                $allowBelowStock,
                null,
                null,
                saleId: (int) $sale->id,
            );
        }
    }
}
