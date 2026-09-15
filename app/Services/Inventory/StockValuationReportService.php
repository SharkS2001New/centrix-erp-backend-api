<?php

namespace App\Services\Inventory;

use App\Services\Auth\UserAccessService;
use App\Services\Catalog\ProductCatalogFilterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stock valuation report: live available qty (on-hand − active reservations)
 * and cost/retail value against that available qty.
 */
class StockValuationReportService
{
    public function __construct(
        protected StockValuationService $valuation,
    ) {}

    /** @return array<string, mixed> */
    public function paginate(Request $request, int $organizationId): array
    {
        $perPage = min((int) $request->input('per_page', 25), 200);

        $branchId = $request->filled('branch_id')
            ? (int) $request->input('branch_id')
            : null;

        if ($branchId === null && $request->user()) {
            $branchId = app(UserAccessService::class)->branchId($request->user());
        }

        $branchIds = $branchId
            ? [$branchId]
            : DB::table('branches')
                ->where('organization_id', $organizationId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        if ($branchIds === []) {
            return [
                'data' => [],
                'total' => 0,
                'per_page' => $perPage,
                'current_page' => 1,
                'last_page' => 1,
                'summary' => ['row_count' => 0, 'cost_value' => 0.0, 'stock_value' => 0.0],
            ];
        }

        $shopOnHandSql = 'COALESCE(cs.shop_quantity, 0)';
        $storeOnHandSql = 'COALESCE(cs.store_quantity, 0)';
        $shopAvailableSql = "GREATEST(0, {$shopOnHandSql} - COALESCE(rsrv.reserved_shop, 0))";
        $storeAvailableSql = "GREATEST(0, {$storeOnHandSql} - COALESCE(rsrv.reserved_store, 0))";
        $availableTotalSql = "({$shopAvailableSql} + {$storeAvailableSql})";

        $unitCostSql = $this->valuation->effectiveUnitCostExpression('p', 'b', 'lrc');
        $shopCostValueSql = $this->valuation->stockCostValueSql($shopAvailableSql, 'p', 'b', 'u', 'lrc');
        $storeCostValueSql = $this->valuation->stockCostValueSql($storeAvailableSql, 'p', 'b', 'u', 'lrc');
        $totalCostValueSql = $this->valuation->stockCostValueSql($availableTotalSql, 'p', 'b', 'u', 'lrc');
        $retailValueSql = $this->valuation->stockRetailValueSql($availableTotalSql, 'p', 'u');

        $reservedSub = DB::table('stock_reservations')
            ->whereNull('released_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->whereIn('branch_id', $branchIds)
            ->groupBy('branch_id', 'product_code')
            ->select([
                'branch_id',
                'product_code',
                DB::raw("SUM(CASE WHEN stock_location = 'shop' THEN quantity ELSE 0 END) as reserved_shop"),
                DB::raw("SUM(CASE WHEN stock_location = 'store' THEN quantity ELSE 0 END) as reserved_store"),
            ]);

        $query = DB::table('current_stock as cs')
            ->join('branches as b', 'b.id', '=', 'cs.branch_id')
            ->join('products as p', function ($join) {
                $join->on('p.product_code', '=', 'cs.product_code')
                    ->on('p.organization_id', '=', 'b.organization_id');
            })
            ->join('uoms as u', 'u.id', '=', 'p.unit_id')
            ->leftJoinSub($reservedSub, 'rsrv', function ($join) {
                $join->on('rsrv.product_code', '=', 'cs.product_code')
                    ->on('rsrv.branch_id', '=', 'cs.branch_id');
            })
            ->where('b.organization_id', $organizationId)
            ->whereNull('p.deleted_at')
            ->whereIn('cs.branch_id', $branchIds);

        $this->valuation->joinLatestReceiptCosts($query, 'p', 'b', 'lrc');

        if ($request->filled('product_code')) {
            $query->where('p.product_code', $request->input('product_code'));
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('p.product_code', 'like', "%{$search}%")
                    ->orWhere('p.product_name', 'like', "%{$search}%");
            });
        }

        if ($subcategoryId = ProductCatalogFilterService::resolveSubcategoryFilterId($request)) {
            $query->where('p.subcategory_id', $subcategoryId);
        }

        if ($location = (string) $request->input('location', '')) {
            if ($location === 'shop') {
                $query->whereRaw("{$shopAvailableSql} > 0");
            } elseif ($location === 'store') {
                $query->whereRaw("{$storeAvailableSql} > 0");
            }
        }

        if ($request->boolean('in_stock_only')) {
            $query->whereRaw("{$availableTotalSql} > 0");
        }

        $query->select([
            'b.organization_id',
            'cs.branch_id',
            'p.product_code',
            'p.product_name',
            'u.conversion_factor',
            'u.full_name as uom_name',
            'u.small_packaging_label',
            'u.middle_packaging_label',
            'u.middle_factor',
            'u.uom_type',
            'u.uses_small_packaging',
            'p.last_cost_price',
            'p.unit_price',
            DB::raw("({$unitCostSql}) as effective_unit_cost"),
            DB::raw("{$shopOnHandSql} as shop_on_hand"),
            DB::raw("{$storeOnHandSql} as store_on_hand"),
            DB::raw("{$shopAvailableSql} as shop_quantity"),
            DB::raw("{$storeAvailableSql} as store_quantity"),
            DB::raw("{$shopAvailableSql} as shop_qty"),
            DB::raw("{$storeAvailableSql} as store_qty"),
            DB::raw("{$availableTotalSql} as total_qty"),
            DB::raw("{$shopCostValueSql} as shop_cost_value"),
            DB::raw("{$storeCostValueSql} as store_cost_value"),
            DB::raw("{$totalCostValueSql} as cost_value"),
            DB::raw("{$totalCostValueSql} as stock_value"),
            DB::raw("{$retailValueSql} as retail_value"),
        ]);

        $summaryRaw = (clone $query)
            ->reorder()
            ->select([
                DB::raw('COUNT(*) as row_count'),
                DB::raw("COALESCE(SUM({$shopCostValueSql}), 0) as shop_cost_value"),
                DB::raw("COALESCE(SUM({$storeCostValueSql}), 0) as store_cost_value"),
                DB::raw("COALESCE(SUM({$totalCostValueSql}), 0) as cost_value"),
                DB::raw("COALESCE(SUM({$retailValueSql}), 0) as retail_value"),
            ])
            ->first();

        $costValue = round((float) ($summaryRaw->cost_value ?? 0), 2);
        $summary = [
            'row_count' => (int) ($summaryRaw->row_count ?? 0),
            'shop_cost_value' => round((float) ($summaryRaw->shop_cost_value ?? 0), 2),
            'store_cost_value' => round((float) ($summaryRaw->store_cost_value ?? 0), 2),
            'cost_value' => $costValue,
            'stock_value' => $costValue,
            'retail_value' => round((float) ($summaryRaw->retail_value ?? 0), 2),
        ];

        $paginator = $query->orderBy('p.product_name')->paginate($perPage);
        $payload = $paginator->toArray();
        $payload['summary'] = $summary;

        return $payload;
    }
}
