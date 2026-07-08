<?php

namespace App\Services\Inventory;

use App\Models\CurrentStock;
use App\Models\Product;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BranchStockService
{
    public function __construct(protected UserAccessService $access) {}

    public function resolveBranchIdOptional(?User $user, ?Request $request = null): ?int
    {
        if (! $user) {
            return null;
        }

        $requested = $request && $request->filled('branch_id')
            ? (int) $request->input('branch_id')
            : null;

        $limitedBranch = $this->access->branchId($user);
        if ($limitedBranch !== null) {
            if ($requested !== null && $requested !== $limitedBranch) {
                abort(403, 'You can only operate within your assigned branch.');
            }

            return $limitedBranch;
        }

        if ($requested !== null && $requested > 0) {
            return $requested;
        }

        if ($user->branch_id) {
            return (int) $user->branch_id;
        }

        $orgId = $this->access->organizationId($user, $request);
        if ($orgId) {
            $branchIds = DB::table('branches')
                ->where('organization_id', $orgId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (count($branchIds) === 1) {
                return $branchIds[0];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function overlayPayload(array $payload, int $branchId): array
    {
        $code = (string) ($payload['product_code'] ?? '');
        if ($code === '') {
            return $payload;
        }

        $row = CurrentStock::query()
            ->where('product_code', $code)
            ->where('branch_id', $branchId)
            ->first();

        $shop = (float) ($row?->shop_quantity ?? 0);
        $store = (float) ($row?->store_quantity ?? 0);
        $reserved = $this->activeReservedQtyMap([$code], $branchId);
        $reservedShop = (float) ($reserved[$code]['shop'] ?? 0);
        $reservedStore = (float) ($reserved[$code]['store'] ?? 0);

        $payload['stock_in_shop'] = $shop;
        $payload['stock_in_store'] = $store;
        $payload['stock_reserved_shop'] = $reservedShop;
        $payload['stock_reserved_store'] = $reservedStore;
        $payload['stock_available_shop'] = max(0, $shop - $reservedShop);
        $payload['stock_available_store'] = max(0, $store - $reservedStore);
        $payload['branch_stock'] = [
            'branch_id' => $branchId,
            'shop_quantity' => $shop,
            'store_quantity' => $store,
            'shop_reserved' => $reservedShop,
            'store_reserved' => $reservedStore,
            'shop_available' => max(0, $shop - $reservedShop),
            'store_available' => max(0, $store - $reservedStore),
        ];

        return $payload;
    }

    /**
     * Sales channels (mobile/POS) historically read stock_in_shop — expose net available there
     * while preserving on-hand figures for admin consumers.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function applySalesConsumerStock(
        array $payload,
        ?string $saleLocation = null,
        bool $splitShopStore = false,
    ): array {
        if ($splitShopStore) {
            if (array_key_exists('stock_available_shop', $payload)) {
                $payload['stock_on_hand_shop'] = $payload['stock_in_shop'] ?? null;
                $payload['stock_in_shop'] = $payload['stock_available_shop'];
            }

            if (array_key_exists('stock_available_store', $payload)) {
                $payload['stock_on_hand_store'] = $payload['stock_in_store'] ?? null;
                $payload['stock_in_store'] = $payload['stock_available_store'];
            }

            $payload['sales_stock_split'] = true;

            return $payload;
        }

        $location = in_array($saleLocation, ['shop', 'store'], true) ? $saleLocation : 'shop';
        $availableKey = $location === 'store' ? 'stock_available_store' : 'stock_available_shop';
        $onHandKey = $location === 'store' ? 'stock_in_store' : 'stock_in_shop';
        $onHandAvailableKey = $location === 'store' ? 'stock_on_hand_store' : 'stock_on_hand_shop';

        if (array_key_exists($availableKey, $payload)) {
            $payload[$onHandAvailableKey] = $payload[$onHandKey] ?? null;
            $payload['stock_in_shop'] = $payload[$availableKey];
            $payload['sales_stock_location'] = $location;
        } elseif (array_key_exists('stock_available_shop', $payload)) {
            $payload['stock_on_hand_shop'] = $payload['stock_in_shop'];
            $payload['stock_in_shop'] = $payload['stock_available_shop'];
            $payload['sales_stock_location'] = 'shop';
        }

        if (array_key_exists('stock_available_store', $payload)) {
            $payload['stock_on_hand_store'] = $payload['stock_in_store'];
            if ($location === 'store') {
                $payload['stock_in_store'] = $payload['stock_available_store'];
            }
        }

        return $payload;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    public function overlayCollection(Collection $items, int $branchId): Collection
    {
        $codes = $items->pluck('product_code')->filter()->unique()->values()->all();
        if ($codes === []) {
            return $items;
        }

        $rows = CurrentStock::query()
            ->where('branch_id', $branchId)
            ->whereIn('product_code', $codes)
            ->get()
            ->keyBy('product_code');

        $reservedByCode = $this->activeReservedQtyMap($codes, $branchId);

        return $items->map(function (array $item) use ($rows, $reservedByCode, $branchId) {
            $row = $rows->get($item['product_code'] ?? '');
            $shop = (float) ($row?->shop_quantity ?? 0);
            $store = (float) ($row?->store_quantity ?? 0);
            $code = (string) ($item['product_code'] ?? '');
            $reservedShop = (float) ($reservedByCode[$code]['shop'] ?? 0);
            $reservedStore = (float) ($reservedByCode[$code]['store'] ?? 0);
            $item['stock_in_shop'] = $shop;
            $item['stock_in_store'] = $store;
            $item['stock_reserved_shop'] = $reservedShop;
            $item['stock_reserved_store'] = $reservedStore;
            $item['stock_available_shop'] = max(0, $shop - $reservedShop);
            $item['stock_available_store'] = max(0, $store - $reservedStore);
            $item['branch_stock'] = [
                'branch_id' => $branchId,
                'shop_quantity' => $shop,
                'store_quantity' => $store,
                'shop_reserved' => $reservedShop,
                'store_reserved' => $reservedStore,
                'shop_available' => max(0, $shop - $reservedShop),
                'store_available' => max(0, $store - $reservedStore),
            ];

            return $item;
        });
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function joinBranchStock(Builder $query, int $branchId, string $alias = 'branch_stock'): void
    {
        if ($this->queryHasJoin($query, $alias)) {
            return;
        }

        $query->leftJoin("current_stock as {$alias}", function ($join) use ($branchId, $alias) {
            $join->on("{$alias}.product_code", '=', 'products.product_code')
                ->where("{$alias}.branch_id", '=', $branchId);
        });
    }

    public function branchStockTotalSql(?int $branchId, string $alias = 'branch_stock'): string
    {
        if ($branchId) {
            return "COALESCE({$alias}.shop_quantity, 0) + COALESCE({$alias}.store_quantity, 0)";
        }

        return '(COALESCE(products.stock_in_shop, 0) + COALESCE(products.stock_in_store, 0))';
    }

    /**
     * Limit catalog results to products with sellable quantity at the consumer stock location.
     *
     * @param  Builder<Product>  $query
     */
    public function applyConsumerAvailableStockFilter(
        Builder $query,
        ?int $branchId,
        string $channel,
        array $inventorySettings,
        array $salesSettings,
    ): void {
        if ($branchId) {
            $alias = 'branch_stock';
            $this->joinBranchStock($query, $branchId, $alias);

            if (! empty($salesSettings['retail_shop_wholesale_store_stock'])) {
                $shopAvailable = $this->availableQuantitySql($branchId, 'shop', $alias);
                $storeAvailable = $this->availableQuantitySql($branchId, 'store', $alias);
                // Wholesale lines draw from store; retail lines from shop. W/R products can sell from either.
                $query->whereRaw("(
                    (COALESCE(products.sell_on_retail, 0) = 0 AND ({$storeAvailable}) > 0)
                    OR (products.sell_on_retail = 1 AND (({$shopAvailable}) > 0 OR ({$storeAvailable}) > 0))
                )");

                return;
            }

            $location = SaleStockLocationResolver::forCatalogList(
                $channel,
                $inventorySettings,
                $salesSettings,
            );
            $available = $this->availableQuantitySql($branchId, $location, $alias);
            $query->whereRaw("({$available}) > 0");

            return;
        }

        $query->whereRaw('(COALESCE(products.stock_in_shop, 0) + COALESCE(products.stock_in_store, 0)) > 0');
    }

    protected function availableQuantitySql(int $branchId, string $location, string $stockAlias): string
    {
        $location = $location === 'store' ? 'store' : 'shop';
        $qtyColumn = $location === 'store' ? 'store_quantity' : 'shop_quantity';
        $reservedSubquery = "(SELECT COALESCE(SUM(sr.quantity), 0)
            FROM stock_reservations sr
            WHERE sr.released_at IS NULL
              AND (sr.expires_at IS NULL OR sr.expires_at > NOW())
              AND sr.product_code = products.product_code
              AND sr.branch_id = {$branchId}
              AND sr.stock_location = '{$location}')";

        return "GREATEST(0, COALESCE({$stockAlias}.{$qtyColumn}, 0) - {$reservedSubquery})";
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function applyStockStatusFilter(Builder $query, string $stockStatus, ?int $branchId): void
    {
        if ($stockStatus === '') {
            return;
        }

        $alias = 'branch_stock';
        if ($branchId) {
            $this->joinBranchStock($query, $branchId, $alias);
        }

        $total = $this->branchStockTotalSql($branchId, $alias);

        if ($stockStatus === 'out_of_stock') {
            $query->whereRaw("({$total}) <= 0");
        } elseif ($stockStatus === 'low_stock') {
            $query->whereRaw("({$total}) > 0")
                ->where('products.reorder_point', '>', 0)
                ->whereRaw("({$total}) <= products.reorder_point");
        } elseif ($stockStatus === 'in_stock') {
            $query->whereRaw("({$total}) > 0")
                ->where(function ($inner) use ($total) {
                    $inner->where('products.reorder_point', '<=', 0)
                        ->orWhereRaw("({$total}) > products.reorder_point");
                });
        }
    }

    /**
     * @param  Builder<Product>  $query
     */
    protected function queryHasJoin(Builder $query, string $alias): bool
    {
        $joins = $query->getQuery()->joins ?? [];

        foreach ($joins as $join) {
            if (is_string($join->table) && str_contains($join->table, $alias)) {
                return true;
            }
        }

        return false;
    }

    protected function activeReservedQty(string $productCode, int $branchId, string $location): float
    {
        $map = $this->activeReservedQtyMap([$productCode], $branchId);
        $location = $location === 'store' ? 'store' : 'shop';

        return (float) ($map[$productCode][$location] ?? 0);
    }

    /**
     * @param  list<string>  $productCodes
     * @return array<string, array{shop: float, store: float}>
     */
    protected function activeReservedQtyMap(array $productCodes, int $branchId): array
    {
        $productCodes = array_values(array_unique(array_filter($productCodes)));
        if ($productCodes === []) {
            return [];
        }

        $rows = DB::table('stock_reservations')
            ->whereNull('released_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where('branch_id', $branchId)
            ->whereIn('product_code', $productCodes)
            ->groupBy('product_code', 'stock_location')
            ->selectRaw('product_code, stock_location, COALESCE(SUM(quantity), 0) AS qty')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $code = (string) $row->product_code;
            if (! isset($map[$code])) {
                $map[$code] = ['shop' => 0.0, 'store' => 0.0];
            }
            $location = $row->stock_location === 'store' ? 'store' : 'shop';
            $map[$code][$location] = (float) $row->qty;
        }

        return $map;
    }
}
