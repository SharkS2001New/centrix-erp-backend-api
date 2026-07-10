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
use App\Services\Inventory\ProductStockDenormService;
use App\Services\Inventory\SaleStockLocationResolver;
use App\Services\Inventory\StockValuationService;
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
        app(ProductStockDenormService::class)->syncFromCurrentStock($productCode, $branchId);
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
            $this->releaseExpiredReservations($cartId);
            $available = $this->stockNetAvailable($productCode, $branchId, $location);
            if ($quantity > $available) {
                throw new InvalidArgumentException(
                    "Cannot reserve {$quantity}; available {$available}."
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

    protected function orgProduct(?int $orgId, string $productCode): ?Product
    {
        if (! $orgId) {
            return Product::query()->find($productCode);
        }

        return Product::query()
            ->where('organization_id', $orgId)
            ->where('product_code', $productCode)
            ->first();
    }

    protected function productUnitCost(?int $orgId, string $productCode): ?float
    {
        if (! $orgId) {
            $product = Product::query()->find($productCode);
            $cost = max(0, (float) ($product?->last_cost_price ?? 0));

            return $cost > 0 ? $cost : null;
        }

        $cost = app(StockValuationService::class)->effectiveUnitCostForProduct($orgId, $productCode);

        return $cost > 0 ? $cost : null;
    }

    /** @param  array<string, mixed>  $data */
    protected function withProductUnitCost(array $data, ?int $orgId): array
    {
        if (! array_key_exists('unit_cost', $data) || $data['unit_cost'] === null) {
            $cost = $this->productUnitCost($orgId, (string) $data['product_code']);
            if ($cost !== null) {
                $data['unit_cost'] = $cost;
            }
        }

        return $data;
    }

    protected function postInventoryMovementJournal(
        User $user,
        \App\Services\Erp\CapabilityGate $gate,
        string $movementType,
        float $qty,
        ?float $unitCost,
        string $entryNumber,
        string $description,
        ?int $branchId,
        string $referenceType,
        int $referenceId,
        string $productCode,
    ): void {
        $factor = \App\Services\Inventory\StockCostCalculation::conversionFactorForOrganizationProduct(
            (int) $user->organization_id,
            $productCode,
        );
        $amount = app(\App\Services\Accounting\InventoryMovementJournalService::class)
            ->amountFromQtyCost($qty, $unitCost, $factor);
        if ($amount === null) {
            return;
        }

        $this->postInventoryMovementJournalAmount(
            $user,
            $gate,
            $movementType,
            $amount,
            $entryNumber,
            $description,
            $branchId,
            $referenceType,
            $referenceId,
        );
    }

    protected function postInventoryMovementJournalAmount(
        User $user,
        \App\Services\Erp\CapabilityGate $gate,
        string $movementType,
        float $amount,
        string $entryNumber,
        string $description,
        ?int $branchId,
        string $referenceType,
        int $referenceId,
    ): void {
        if ($amount <= 0) {
            return;
        }

        app(\App\Services\Accounting\InventoryMovementJournalService::class)->postIfEnabled(
            gate: $gate,
            user: $user,
            movementType: $movementType,
            amount: $amount,
            entryNumber: $entryNumber,
            description: $description,
            branchId: $branchId,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );
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
        $line = \App\Models\CartLine::query()->find($cartLineId);

        StockReservation::where('cart_line_id', $cartLineId)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);

        // Orphan cart holds (e.g. before bind after restore-to-cart) for this line's SKU.
        if ($line) {
            StockReservation::query()
                ->where('cart_id', $line->cart_id)
                ->where('product_code', $line->product_code)
                ->whereNull('cart_line_id')
                ->whereNull('released_at')
                ->where('quantity', (float) $line->quantity)
                ->update(['released_at' => now()]);
        }
    }

    /**
     * After sale reservations move to a cart, attach each hold to its cart line so
     * line updates/releases do not leave orphan reservations blocking stock.
     */
    protected function bindCartReservationsToLines(\App\Models\TemporaryCart $cart, User $user, \App\Services\Erp\CapabilityGate $gate): void
    {
        $cart->loadMissing('lines');
        $inventorySettings = $gate->moduleSettings('inventory');
        $salesSettings = $gate->moduleSettings('sales');

        $unbound = StockReservation::query()
            ->where('cart_id', $cart->id)
            ->whereNull('cart_line_id')
            ->whereNull('released_at')
            ->orderBy('id')
            ->get();

        if ($unbound->isEmpty()) {
            return;
        }

        foreach ($cart->lines as $line) {
            $product = Product::query()
                ->where('organization_id', $user->organization_id)
                ->where('product_code', $line->product_code)
                ->first();
            if (! $product) {
                continue;
            }

            $location = $this->resolveSaleLineStockLocation(
                (string) $cart->channel,
                $inventorySettings,
                $salesSettings,
                $product,
                (bool) $line->on_wholesale_retail,
            );

            $match = $unbound->first(function ($reservation) use ($line, $location) {
                return (string) $reservation->product_code === (string) $line->product_code
                    && (string) $reservation->stock_location === $location
                    && abs((float) $reservation->quantity - (float) $line->quantity) < 0.0001;
            });

            if ($match) {
                $match->update(['cart_line_id' => $line->id]);
                $unbound = $unbound->reject(fn ($r) => (int) $r->id === (int) $match->id);
            }
        }
    }

    protected function originalSaleDeductionTxn(int $saleId, string $productCode): ?InventoryTransaction
    {
        return InventoryTransaction::query()
            ->where('reference_id', $saleId)
            ->where('product_code', $productCode)
            ->where('quantity_change', '<', 0)
            ->whereIn('reference_type', ['sale', 'dispatch_trip', 'sale_line_edit'])
            ->orderByDesc('id')
            ->first();
    }

    protected function resolveReturnStockLocationForSaleLine(
        Sale $sale,
        SaleItem $saleItem,
        User $user,
        \App\Services\Erp\CapabilityGate $gate,
    ): string {
        $ledgerLocation = $this->originalSaleDeductionTxn((int) $sale->id, (string) $saleItem->product_code)
            ?->stock_location;

        if ($ledgerLocation) {
            return (string) $ledgerLocation;
        }

        $product = Product::query()
            ->where('organization_id', $user->organization_id)
            ->where('product_code', $saleItem->product_code)
            ->first();

        if (! $product) {
            return $this->saleStockLocation((string) $sale->channel, $gate->moduleSettings('inventory'));
        }

        return $this->resolveSaleLineStockLocation(
            (string) $sale->channel,
            $gate->moduleSettings('inventory'),
            $gate->moduleSettings('sales'),
            $product,
            (bool) $saleItem->on_wholesale_retail,
        );
    }

    protected function resolveReturnUnitCost(?int $saleId, string $productCode, ?int $orgId = null): ?float
    {
        if ($saleId) {
            $txnCost = $this->originalSaleDeductionTxn($saleId, $productCode)?->unit_cost;
            if ($txnCost !== null && (float) $txnCost > 0) {
                return (float) $txnCost;
            }
        }

        if (! $orgId) {
            return null;
        }

        $lastCost = Product::query()
            ->where('organization_id', $orgId)
            ->where('product_code', $productCode)
            ->value('last_cost_price');

        $cost = max(0, (float) ($lastCost ?? 0));

        return $cost > 0 ? $cost : null;
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
            $product = Product::query()
                ->where('organization_id', $user->organization_id)
                ->where('product_code', $item->product_code)
                ->first();
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
