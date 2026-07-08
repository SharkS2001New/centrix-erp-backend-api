<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;

class StockValuationService
{
    /**
     * Effective unit cost: product last cost, else latest purchase receipt, else zero.
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
     * @return array{shop_value: float, store_value: float, value: float, branch_id: int|null}
     */
    public function summarize(?int $organizationId, ?int $branchId = null): array
    {
        if (! $organizationId) {
            return [
                'shop_value' => 0.0,
                'store_value' => 0.0,
                'value' => 0.0,
                'branch_id' => $branchId,
            ];
        }

        $unitCost = $this->effectiveUnitCostExpression();

        $query = DB::table('current_stock as cs')
            ->join('branches as b', 'b.id', '=', 'cs.branch_id')
            ->join('products as p', function ($join) {
                $join->on('p.product_code', '=', 'cs.product_code')
                    ->on('p.organization_id', '=', 'b.organization_id');
            })
            ->where('b.organization_id', $organizationId)
            ->whereNull('p.deleted_at');

        if ($branchId !== null) {
            $query->where('cs.branch_id', $branchId);
        }

        $totals = $query
            ->selectRaw("COALESCE(SUM(cs.shop_quantity * ({$unitCost})), 0) as shop_value")
            ->selectRaw("COALESCE(SUM(cs.store_quantity * ({$unitCost})), 0) as store_value")
            ->first();

        $shopValue = round((float) ($totals->shop_value ?? 0), 2);
        $storeValue = round((float) ($totals->store_value ?? 0), 2);

        return [
            'shop_value' => $shopValue,
            'store_value' => $storeValue,
            'value' => round($shopValue + $storeValue, 2),
            'branch_id' => $branchId,
        ];
    }
}
