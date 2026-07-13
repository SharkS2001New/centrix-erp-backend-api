<?php

namespace App\Services\Inventory;

use App\Services\Catalog\ProductCatalogFilterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockChainReportService
{
    private const SALE_TYPES = ['POS_SALE', 'MOBILE_SALE', 'BACKEND_SALE'];

    /** @return array<string, mixed> */
    public function paginate(Request $request, int $organizationId): array
    {
        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);
        $page = max((int) $request->input('page', 1), 1);

        $branchId = $request->filled('branch_id')
            ? (int) $request->input('branch_id')
            : null;

        if ($branchId === null && $request->user()) {
            $branchId = app(\App\Services\Auth\UserAccessService::class)->branchId($request->user());
        }

        $branchIds = $branchId
            ? [$branchId]
            : DB::table('branches')
                ->where('organization_id', $organizationId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        if ($branchIds === []) {
            return $this->emptyPage($perPage, $page);
        }

        $fromDate = $request->filled('from_date') ? (string) $request->input('from_date') : null;
        $toDate = $request->filled('to_date') ? (string) $request->input('to_date') : null;
        $hasPeriod = $fromDate !== null || $toDate !== null;

        $saleTypeList = implode(', ', array_map(fn ($t) => "'{$t}'", self::SALE_TYPES));

        $lifecycleSub = DB::table('inventory_transactions as it')
            ->join('products as p', function ($join) use ($organizationId) {
                $join->on('p.product_code', '=', 'it.product_code')
                    ->where('p.organization_id', '=', $organizationId)
                    ->whereNull('p.deleted_at');
            })
            ->whereIn('it.branch_id', $branchIds)
            ->groupBy('it.branch_id', 'it.product_code')
            ->select([
                'it.branch_id',
                'it.product_code',
                DB::raw("MIN(CASE WHEN it.transaction_type = 'PURCHASE' AND it.quantity_change > 0 THEN it.created_at END) AS first_received_at"),
                DB::raw("MIN(CASE WHEN it.transaction_type = 'ADJUSTMENT' AND it.quantity_change > 0 THEN it.created_at END) AS first_adjustment_at"),
                DB::raw("MIN(CASE
                    WHEN it.quantity_change > 0
                     AND it.transaction_type IN ('PURCHASE', 'ADJUSTMENT')
                    THEN it.created_at
                END) AS first_entered_at"),
                DB::raw("MIN(CASE WHEN it.transaction_type IN ({$saleTypeList}) THEN it.created_at END) AS first_sold_at"),
                DB::raw('MAX(it.created_at) AS last_movement_at'),
            ]);

        $periodTotalsSub = DB::table('inventory_transactions as it')
            ->join('products as p', function ($join) use ($organizationId) {
                $join->on('p.product_code', '=', 'it.product_code')
                    ->where('p.organization_id', '=', $organizationId)
                    ->whereNull('p.deleted_at');
            })
            ->leftJoin('sale_items as si', function ($join) {
                $join->on('si.sale_id', '=', 'it.reference_id')
                    ->on('si.product_code', '=', 'it.product_code')
                    ->where('it.reference_type', '=', 'sale');
            })
            ->whereIn('it.branch_id', $branchIds)
            ->when($fromDate, fn ($q) => $q->whereDate('it.created_at', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('it.created_at', '<=', $toDate))
            ->groupBy('it.branch_id', 'it.product_code')
            ->select([
                'it.branch_id',
                'it.product_code',
                DB::raw("SUM(CASE
                    WHEN it.transaction_type = 'PURCHASE' AND it.quantity_change > 0
                    THEN it.quantity_change * COALESCE(it.unit_cost, p.last_cost_price, 0)
                    ELSE 0
                END) AS total_received"),
                DB::raw("SUM(CASE
                    WHEN it.transaction_type IN ({$saleTypeList})
                    THEN COALESCE(
                        si.amount,
                        ABS(it.quantity_change) * COALESCE(si.selling_price, p.unit_price, 0)
                    )
                    ELSE 0
                END) AS total_sold"),
            ]);

        $keysSub = DB::table('inventory_transactions as it')
            ->join('products as p', function ($join) use ($organizationId) {
                $join->on('p.product_code', '=', 'it.product_code')
                    ->where('p.organization_id', '=', $organizationId)
                    ->whereNull('p.deleted_at');
            })
            ->whereIn('it.branch_id', $branchIds)
            ->when($hasPeriod, function ($q) use ($fromDate, $toDate) {
                if ($fromDate) {
                    $q->whereDate('it.created_at', '>=', $fromDate);
                }
                if ($toDate) {
                    $q->whereDate('it.created_at', '<=', $toDate);
                }
            })
            ->select('it.branch_id', 'it.product_code')
            ->distinct();

        $query = DB::query()
            ->fromSub($keysSub, 'k')
            ->join('products as p', function ($join) use ($organizationId) {
                $join->on('p.product_code', '=', 'k.product_code')
                    ->where('p.organization_id', '=', $organizationId)
                    ->whereNull('p.deleted_at');
            })
            ->leftJoin('uoms as u', 'u.id', '=', 'p.unit_id')
            ->joinSub($lifecycleSub, 'lc', function ($join) {
                $join->on('lc.branch_id', '=', 'k.branch_id')
                    ->on('lc.product_code', '=', 'k.product_code');
            })
            ->leftJoinSub($periodTotalsSub, 'pt', function ($join) {
                $join->on('pt.branch_id', '=', 'k.branch_id')
                    ->on('pt.product_code', '=', 'k.product_code');
            })
            ->leftJoin('current_stock as cs', function ($join) {
                $join->on('cs.product_code', '=', 'k.product_code')
                    ->on('cs.branch_id', '=', 'k.branch_id');
            })
            ->select([
                'k.branch_id',
                'k.product_code',
                'p.product_name',
                'p.unit_id',
                'u.full_name as uom_name',
                'u.conversion_factor',
                'u.small_packaging_label',
                'u.middle_packaging_label',
                'u.middle_factor',
                'u.uom_type',
                'lc.first_received_at',
                'lc.first_adjustment_at',
                'lc.first_entered_at',
                'lc.first_sold_at',
                'lc.last_movement_at',
                DB::raw('COALESCE(pt.total_received, 0) AS total_received'),
                DB::raw('COALESCE(pt.total_sold, 0) AS total_sold'),
                DB::raw('COALESCE(cs.shop_quantity, 0) AS current_shop_stock'),
                DB::raw('COALESCE(cs.store_quantity, 0) AS current_store_stock'),
            ]);

        if ($request->filled('product_code')) {
            $query->where('k.product_code', $request->input('product_code'));
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('k.product_code', 'like', "%{$search}%")
                    ->orWhere('p.product_name', 'like', "%{$search}%");
            });
        }

        if ($subcategoryId = ProductCatalogFilterService::resolveSubcategoryFilterId($request)) {
            $query->where('p.subcategory_id', $subcategoryId);
        }

        $paginator = $query
            ->orderBy('p.product_name')
            ->paginate($perPage, ['*'], 'page', $page);

        return $paginator->toArray();
    }

    /** @return array<string, mixed> */
    private function emptyPage(int $perPage, int $page): array
    {
        return [
            'data' => [],
            'total' => 0,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => 1,
        ];
    }
}
