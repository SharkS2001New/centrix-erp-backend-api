<?php

namespace App\Services\Inventory;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class StockValuationService
{
    /**
     * Derived table: latest positive receipt cost per org + product (one scan, joinable).
     */
    public function latestReceiptCostSubquery(): Builder
    {
        return DB::table('stock_receipts as sr')
            ->joinSub(
                DB::table('stock_receipts')
                    ->whereNotNull('cost_price')
                    ->where('cost_price', '>', 0)
                    ->groupBy('organization_id', 'product_code')
                    ->select([
                        'organization_id',
                        'product_code',
                        DB::raw('MAX(id) as max_id'),
                    ]),
                'latest_sr',
                function ($join) {
                    $join->on('latest_sr.max_id', '=', 'sr.id');
                },
            )
            ->select([
                'sr.organization_id',
                'sr.product_code',
                'sr.cost_price',
            ]);
    }

    /**
     * Left-join latest receipt costs onto a stock/product query.
     *
     * @param  Builder  $query
     */
    public function joinLatestReceiptCosts(
        Builder $query,
        string $productAlias = 'p',
        string $organizationAlias = 'b',
        string $receiptAlias = 'lrc',
    ): Builder {
        return $query->leftJoinSub(
            $this->latestReceiptCostSubquery(),
            $receiptAlias,
            function ($join) use ($productAlias, $organizationAlias, $receiptAlias) {
                $join->on("{$receiptAlias}.product_code", '=', "{$productAlias}.product_code")
                    ->on("{$receiptAlias}.organization_id", '=', "{$organizationAlias}.organization_id");
            },
        );
    }

    /**
     * Effective unit cost: product last cost, else latest purchase receipt, else zero.
     * Cost is per purchase/package unit (not per base unit).
     *
     * Prefer {@see joinLatestReceiptCosts()} + $receiptAlias so reports avoid per-row
     * correlated subqueries. When $receiptAlias is null, falls back to a correlated lookup
     * (single-product helpers).
     */
    public function effectiveUnitCostExpression(
        string $productAlias = 'p',
        string $branchAlias = 'b',
        ?string $receiptAlias = null,
    ): string {
        $productCode = "{$productAlias}.product_code";
        $organizationId = $branchAlias !== ''
            ? "{$branchAlias}.organization_id"
            : "{$productAlias}.organization_id";

        if ($receiptAlias !== null && $receiptAlias !== '') {
            return <<<SQL
COALESCE(
    NULLIF({$productAlias}.last_cost_price, 0),
    {$receiptAlias}.cost_price,
    0
)
SQL;
        }

        return <<<SQL
COALESCE(
    NULLIF({$productAlias}.last_cost_price, 0),
    (
        SELECT sr.cost_price
        FROM stock_receipts sr
        WHERE sr.organization_id = {$organizationId}
          AND sr.product_code = {$productCode}
          AND sr.cost_price IS NOT NULL
          AND sr.cost_price > 0
        ORDER BY sr.id DESC
        LIMIT 1
    ),
    0
)
SQL;
    }

    public function stockCostValueSql(
        string $quantityExpression,
        string $productAlias = 'p',
        string $branchAlias = 'b',
        string $uomAlias = 'u',
        ?string $receiptAlias = null,
    ): string {
        $unitCost = $this->effectiveUnitCostExpression($productAlias, $branchAlias, $receiptAlias);

        return StockCostCalculation::costValueSqlExpression($quantityExpression, $unitCost, $uomAlias);
    }

    public function effectiveUnitCostForProduct(int $organizationId, string $productCode): float
    {
        // Single-row helper: correlated subquery is fine.
        $unitCost = $this->effectiveUnitCostExpression('p', '', null);

        return (float) (DB::table('products as p')
            ->where('p.organization_id', $organizationId)
            ->where('p.product_code', $productCode)
            ->whereNull('p.deleted_at')
            ->selectRaw("({$unitCost}) as effective_unit_cost")
            ->value('effective_unit_cost') ?? 0);
    }

    /**
     * @return array{
     *   shop_value: float,
     *   store_value: float,
     *   value: float,
     *   shop_cost_value: float,
     *   store_cost_value: float,
     *   cost_value: float,
     *   branch_id: int|null,
     *   skus_in_stock: int,
     *   skus_low: int,
     *   skus_out: int,
     *   total_available_units: float
     * }
     */
    public function summarize(?int $organizationId, ?int $branchId = null): array
    {
        $empty = [
            'shop_value' => 0.0,
            'store_value' => 0.0,
            'value' => 0.0,
            'shop_cost_value' => 0.0,
            'store_cost_value' => 0.0,
            'cost_value' => 0.0,
            'branch_id' => $branchId,
            'skus_in_stock' => 0,
            'skus_low' => 0,
            'skus_out' => 0,
            'total_available_units' => 0.0,
        ];

        if (! $organizationId) {
            return $empty;
        }

        $shopRetailValueSql = $this->stockRetailValueSql('cs.shop_quantity');
        $storeRetailValueSql = $this->stockRetailValueSql('cs.store_quantity');
        $shopCostValueSql = $this->stockCostValueSql('cs.shop_quantity', 'p', 'b', 'u', 'lrc');
        $storeCostValueSql = $this->stockCostValueSql('cs.store_quantity', 'p', 'b', 'u', 'lrc');

        $query = DB::table('current_stock as cs')
            ->join('branches as b', 'b.id', '=', 'cs.branch_id')
            ->join('products as p', function ($join) {
                $join->on('p.product_code', '=', 'cs.product_code')
                    ->on('p.organization_id', '=', 'b.organization_id');
            })
            ->join('uoms as u', 'u.id', '=', 'p.unit_id')
            ->where('b.organization_id', $organizationId)
            ->whereNull('p.deleted_at');

        $this->joinLatestReceiptCosts($query, 'p', 'b', 'lrc');

        if ($branchId !== null) {
            $query->where('cs.branch_id', $branchId);
        }

        $totals = $query
            ->selectRaw("COALESCE(SUM({$shopRetailValueSql}), 0) as shop_value")
            ->selectRaw("COALESCE(SUM({$storeRetailValueSql}), 0) as store_value")
            ->selectRaw("COALESCE(SUM({$shopCostValueSql}), 0) as shop_cost_value")
            ->selectRaw("COALESCE(SUM({$storeCostValueSql}), 0) as store_cost_value")
            ->first();

        $shopValue = round((float) ($totals->shop_value ?? 0), 2);
        $storeValue = round((float) ($totals->store_value ?? 0), 2);
        $shopCostValue = round((float) ($totals->shop_cost_value ?? 0), 2);
        $storeCostValue = round((float) ($totals->store_cost_value ?? 0), 2);

        // Catalog-wide health (includes zero-stock products missing from current_stock).
        $branchIds = $branchId !== null
            ? [$branchId]
            : DB::table('branches')->where('organization_id', $organizationId)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $health = [
            'skus_in_stock' => 0,
            'skus_low' => 0,
            'skus_out' => 0,
            'total_available_units' => 0.0,
        ];

        if ($branchIds !== []) {
            $qtySql = '(COALESCE(cs.shop_quantity, 0) + COALESCE(cs.store_quantity, 0))';
            $healthRow = DB::table('products as p')
                ->join('branches as br', function ($join) use ($organizationId, $branchIds) {
                    $join->where('br.organization_id', '=', $organizationId)
                        ->whereIn('br.id', $branchIds);
                })
                ->leftJoin('current_stock as cs', function ($join) {
                    $join->on('cs.product_code', '=', 'p.product_code')
                        ->on('cs.branch_id', '=', 'br.id');
                })
                ->where('p.organization_id', $organizationId)
                ->whereNull('p.deleted_at')
                ->selectRaw("SUM(CASE WHEN {$qtySql} > 0 THEN 1 ELSE 0 END) as skus_in_stock")
                ->selectRaw("SUM(CASE WHEN {$qtySql} <= 0 THEN 1 ELSE 0 END) as skus_out")
                ->selectRaw("SUM(CASE WHEN {$qtySql} <= COALESCE(p.reorder_point, 0) THEN 1 ELSE 0 END) as skus_low")
                ->selectRaw("COALESCE(SUM({$qtySql}), 0) as total_available_units")
                ->first();

            $health = [
                'skus_in_stock' => (int) ($healthRow->skus_in_stock ?? 0),
                'skus_low' => (int) ($healthRow->skus_low ?? 0),
                'skus_out' => (int) ($healthRow->skus_out ?? 0),
                'total_available_units' => round((float) ($healthRow->total_available_units ?? 0), 2),
            ];
        }

        return [
            'shop_value' => $shopValue,
            'store_value' => $storeValue,
            'value' => round($shopValue + $storeValue, 2),
            'shop_cost_value' => $shopCostValue,
            'store_cost_value' => $storeCostValue,
            'cost_value' => round($shopCostValue + $storeCostValue, 2),
            'branch_id' => $branchId,
            ...$health,
        ];
    }

    public function stockRetailValueSql(
        string $quantityExpression,
        string $productAlias = 'p',
        string $uomAlias = 'u',
    ): string {
        $converted = StockCostCalculation::convertedQuantitySqlExpression($quantityExpression, $uomAlias);

        return "({$converted} * COALESCE({$productAlias}.unit_price, 0))";
    }
}
