<?php

namespace App\Services\Inventory;

use App\Services\Auth\UserAccessService;
use App\Services\Catalog\ProductCatalogFilterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockOnHandReportService
{
    public function __construct(protected StockValuationService $valuation) {}

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
            ];
        }

        $totalSql = '(COALESCE(cs.shop_quantity, 0) + COALESCE(cs.store_quantity, 0))';
        $unitCostSql = $this->valuation->effectiveUnitCostExpression('p', 'br');
        $shopCostValueSql = $this->valuation->stockCostValueSql('COALESCE(cs.shop_quantity, 0)', 'p', 'br');
        $storeCostValueSql = $this->valuation->stockCostValueSql('COALESCE(cs.store_quantity, 0)', 'p', 'br');
        $totalCostValueSql = $this->valuation->stockCostValueSql($totalSql, 'p', 'br');

        $query = DB::table('products as p')
            ->join('uoms as u', 'u.id', '=', 'p.unit_id')
            ->join('branches as br', function ($join) use ($organizationId, $branchIds) {
                $join->where('br.organization_id', '=', $organizationId)
                    ->whereIn('br.id', $branchIds);
            })
            ->leftJoin('current_stock as cs', function ($join) {
                $join->on('cs.product_code', '=', 'p.product_code')
                    ->on('cs.branch_id', '=', 'br.id');
            })
            ->leftJoin('retail_package_settings as rps', 'rps.product_code', '=', 'p.product_code')
            ->where('p.organization_id', $organizationId)
            ->whereNull('p.deleted_at')
            ->when($request->filled('product_code'), fn ($q) => $q->where('p.product_code', $request->input('product_code')))
            ->select([
                'br.id as branch_id',
                'p.product_code',
                'p.product_name',
                'p.unit_price as wholesale_price',
                'p.last_cost_price',
                DB::raw("({$unitCostSql}) as effective_unit_cost"),
                'u.full_name as uom_name',
                'u.conversion_factor',
                DB::raw('COALESCE(cs.shop_quantity, 0) as shop_quantity'),
                DB::raw('COALESCE(cs.store_quantity, 0) as store_quantity'),
                DB::raw("{$totalSql} as total_base_units"),
                DB::raw("{$shopCostValueSql} as shop_cost_value"),
                DB::raw("{$storeCostValueSql} as store_cost_value"),
                DB::raw("{$totalCostValueSql} as total_cost_value"),
                'p.reorder_point',
                'p.low_stock_alert_enabled',
                DB::raw("CASE WHEN {$totalSql} <= COALESCE(p.reorder_point, 0) THEN 'REORDER' ELSE 'OK' END as product_alert"),
                'rps.max_qty_measure',
                'rps.markup_price',
                'rps.wholesale_markup_price',
            ]);

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
                $query->whereRaw('COALESCE(cs.shop_quantity, 0) > 0');
            } elseif ($location === 'store') {
                $query->whereRaw('COALESCE(cs.store_quantity, 0) > 0');
            }
        }

        if ($request->boolean('in_stock_only')) {
            $query->whereRaw("{$totalSql} > 0");
        }

        $paginator = $query->orderBy('p.product_name')->paginate($perPage);

        return $paginator->toArray();
    }
}
