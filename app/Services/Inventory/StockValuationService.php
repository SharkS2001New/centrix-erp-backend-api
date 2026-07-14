<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;

class StockValuationService
{
    /**
     * Effective unit cost: product last cost, else latest purchase receipt, else zero.
     * Cost is per purchase/package unit (not per base unit).
     */
    public function effectiveUnitCostExpression(string $productAlias = 'p', string $branchAlias = 'b'): string
    {
        $productCode = "{$productAlias}.product_code";
        $organizationId = $branchAlias !== ''
            ? "{$branchAlias}.organization_id"
            : "{$productAlias}.organization_id";

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
    ): string {
        $unitCost = $this->effectiveUnitCostExpression($productAlias, $branchAlias);

        return StockCostCalculation::costValueSqlExpression($quantityExpression, $unitCost, $uomAlias);
    }

    public function effectiveUnitCostForProduct(int $organizationId, string $productCode): float
    {
        $unitCost = $this->effectiveUnitCostExpression('p', '');

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
            'branch_id' => $branchId,
            'skus_in_stock' => 0,
            'skus_low' => 0,
            'skus_out' => 0,
            'total_available_units' => 0.0,
        ];

        if (! $organizationId) {
            return $empty;
        }

        $shopValueSql = $this->stockCostValueSql('cs.shop_quantity');
        $storeValueSql = $this->stockCostValueSql('cs.store_quantity');

        $query = DB::table('current_stock as cs')
            ->join('branches as b', 'b.id', '=', 'cs.branch_id')
            ->join('products as p', function ($join) {
                $join->on('p.product_code', '=', 'cs.product_code')
                    ->on('p.organization_id', '=', 'b.organization_id');
            })
            ->join('uoms as u', 'u.id', '=', 'p.unit_id')
            ->where('b.organization_id', $organizationId)
            ->whereNull('p.deleted_at');

        if ($branchId !== null) {
            $query->where('cs.branch_id', $branchId);
        }

        $totals = $query
            ->selectRaw("COALESCE(SUM({$shopValueSql}), 0) as shop_value")
            ->selectRaw("COALESCE(SUM({$storeValueSql}), 0) as store_value")
            ->first();

        $shopValue = round((float) ($totals->shop_value ?? 0), 2);
        $storeValue = round((float) ($totals->store_value ?? 0), 2);

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
            'branch_id' => $branchId,
            ...$health,
        ];
    }
}
