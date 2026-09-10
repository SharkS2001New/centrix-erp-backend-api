<?php

namespace App\Services\Inventory;

use App\Services\Auth\UserAccessService;
use App\Services\Catalog\ProductCatalogFilterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockOnHandReportService
{
    public function __construct(
        protected StockValuationService $valuation,
        protected BranchStockService $branchStock,
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
            ];
        }

        // Physical on-hand (ledger). Screen columns show available (= on-hand − reserved).
        $shopOnHandSql = 'COALESCE(cs.shop_quantity, 0)';
        $storeOnHandSql = 'COALESCE(cs.store_quantity, 0)';
        $onHandTotalSql = "({$shopOnHandSql} + {$storeOnHandSql})";

        // Cost must use the same qty the UI shows (available). Otherwise fully reserved
        // lines show 0 available with a non-zero stock cost.
        $shopAvailableSql = "GREATEST(0, {$shopOnHandSql} - COALESCE(rsrv.reserved_shop, 0))";
        $storeAvailableSql = "GREATEST(0, {$storeOnHandSql} - COALESCE(rsrv.reserved_store, 0))";
        $availableTotalSql = "({$shopAvailableSql} + {$storeAvailableSql})";

        $unitCostSql = $this->valuation->effectiveUnitCostExpression('p', 'br', 'lrc');
        $shopCostValueSql = $this->valuation->stockCostValueSql($shopAvailableSql, 'p', 'br', 'u', 'lrc');
        $storeCostValueSql = $this->valuation->stockCostValueSql($storeAvailableSql, 'p', 'br', 'u', 'lrc');
        $totalCostValueSql = $this->valuation->stockCostValueSql($availableTotalSql, 'p', 'br', 'u', 'lrc');

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
            ->leftJoinSub($reservedSub, 'rsrv', function ($join) {
                $join->on('rsrv.product_code', '=', 'p.product_code')
                    ->on('rsrv.branch_id', '=', 'br.id');
            })
            ->leftJoin('retail_package_settings as rps', 'rps.product_code', '=', 'p.product_code')
            ->where('p.organization_id', $organizationId)
            ->whereNull('p.deleted_at');

        $this->valuation->joinLatestReceiptCosts($query, 'p', 'br', 'lrc');

        $query
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
                'u.small_packaging_label',
                'u.middle_packaging_label',
                'u.middle_factor',
                'u.uom_type',
                'u.uses_small_packaging',
                DB::raw("{$shopOnHandSql} as shop_quantity"),
                DB::raw("{$storeOnHandSql} as store_quantity"),
                DB::raw("{$onHandTotalSql} as total_base_units"),
                DB::raw("{$shopCostValueSql} as shop_cost_value"),
                DB::raw("{$storeCostValueSql} as store_cost_value"),
                DB::raw("{$totalCostValueSql} as total_cost_value"),
                'p.reorder_point',
                'p.low_stock_alert_enabled',
                DB::raw("CASE WHEN {$availableTotalSql} <= COALESCE(p.reorder_point, 0) THEN 'REORDER' ELSE 'OK' END as product_alert"),
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
                $query->whereRaw("{$shopAvailableSql} > 0");
            } elseif ($location === 'store') {
                $query->whereRaw("{$storeAvailableSql} > 0");
            }
        }

        if ($request->boolean('in_stock_only')) {
            $query->whereRaw("{$availableTotalSql} > 0");
        }

        if ($request->boolean('out_of_stock_only')) {
            $query->whereRaw("{$availableTotalSql} <= 0");
        }

        $sort = strtolower(trim((string) $request->input('sort', '')));
        if ($sort === 'available_desc') {
            $query->orderByRaw("{$availableTotalSql} desc")->orderBy('p.product_name');
        } else {
            $query->orderBy('p.product_name');
        }

        $includeSummary = $request->boolean('include_summary', true);
        $summaryOnly = $request->boolean('summary_only', false);

        if ($summaryOnly || $includeSummary) {
            $summaryRaw = (clone $query)
                ->reorder()
                ->select([
                    DB::raw('COUNT(*) as row_count'),
                    DB::raw("COALESCE(SUM({$shopCostValueSql}), 0) as shop_cost_value"),
                    DB::raw("COALESCE(SUM({$storeCostValueSql}), 0) as store_cost_value"),
                    DB::raw("COALESCE(SUM({$totalCostValueSql}), 0) as total_cost_value"),
                ])
                ->first();

            $summary = [
                'row_count' => (int) ($summaryRaw->row_count ?? 0),
                'shop_cost_value' => round((float) ($summaryRaw->shop_cost_value ?? 0), 2),
                'store_cost_value' => round((float) ($summaryRaw->store_cost_value ?? 0), 2),
                'total_cost_value' => round((float) ($summaryRaw->total_cost_value ?? 0), 2),
                'cost_value' => round((float) ($summaryRaw->total_cost_value ?? 0), 2),
            ];

            if ($summaryOnly) {
                return [
                    'data' => [],
                    'total' => $summary['row_count'],
                    'per_page' => $perPage,
                    'current_page' => 1,
                    'last_page' => 1,
                    'summary' => $summary,
                ];
            }
        } else {
            $summary = null;
        }

        $paginator = $query->paginate($perPage);
        $payload = $paginator->toArray();
        $payload['data'] = $this->branchStock->attachAvailabilityToRows($payload['data'] ?? []);
        if ($summary !== null) {
            $payload['summary'] = $summary;
        }

        return $payload;
    }
}
