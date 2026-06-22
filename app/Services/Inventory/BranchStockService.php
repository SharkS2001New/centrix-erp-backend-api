<?php

namespace App\Services\Inventory;

use App\Models\CurrentStock;
use App\Models\Product;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

        return $user->branch_id ? (int) $user->branch_id : null;
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

        $shop = (float) ($row->shop_quantity ?? 0);
        $store = (float) ($row->store_quantity ?? 0);

        $payload['stock_in_shop'] = $shop;
        $payload['stock_in_store'] = $store;
        $payload['branch_stock'] = [
            'branch_id' => $branchId,
            'shop_quantity' => $shop,
            'store_quantity' => $store,
        ];

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

        return $items->map(function (array $item) use ($rows, $branchId) {
            $row = $rows->get($item['product_code'] ?? '');
            $shop = (float) ($row->shop_quantity ?? 0);
            $store = (float) ($row->store_quantity ?? 0);
            $item['stock_in_shop'] = $shop;
            $item['stock_in_store'] = $store;
            $item['branch_stock'] = [
                'branch_id' => $branchId,
                'shop_quantity' => $shop,
                'store_quantity' => $store,
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
}
