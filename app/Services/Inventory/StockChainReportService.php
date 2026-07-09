<?php

namespace App\Services\Inventory;

use App\Services\Auth\UserAccessService;
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
            return $this->emptyPage($perPage, $page);
        }

        $receivedValueSql = <<<'SQL'
SUM(CASE
    WHEN it.transaction_type = 'PURCHASE' AND it.quantity_change > 0
    THEN it.quantity_change * COALESCE(it.unit_cost, p.last_cost_price, 0)
    ELSE 0
END)
SQL;

        $soldValueSql = <<<'SQL'
SUM(CASE
    WHEN it.transaction_type IN ('POS_SALE', 'MOBILE_SALE', 'BACKEND_SALE')
    THEN COALESCE(
        si.amount,
        ABS(it.quantity_change) * COALESCE(si.selling_price, p.unit_price, 0)
    )
    ELSE 0
END)
SQL;

        $query = DB::table('inventory_transactions as it')
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
            ->leftJoin('current_stock as cs', function ($join) {
                $join->on('cs.product_code', '=', 'it.product_code')
                    ->on('cs.branch_id', '=', 'it.branch_id');
            })
            ->whereIn('it.branch_id', $branchIds)
            ->when($request->filled('product_code'), fn ($q) => $q->where('it.product_code', $request->input('product_code')))
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('it.created_at', '>=', $request->input('from_date')))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('it.created_at', '<=', $request->input('to_date')))
            ->groupBy([
                'it.branch_id',
                'it.product_code',
                'p.product_name',
                'p.unit_id',
                'cs.shop_quantity',
                'cs.store_quantity',
            ])
            ->select([
                'it.branch_id',
                'it.product_code',
                'p.product_name',
                'p.unit_id',
                DB::raw("MIN(CASE WHEN it.transaction_type = 'PURCHASE' THEN it.created_at END) as first_received_at"),
                DB::raw('MIN(CASE WHEN it.transaction_type IN (\'POS_SALE\', \'MOBILE_SALE\', \'BACKEND_SALE\') THEN it.created_at END) as first_sold_at'),
                DB::raw('MAX(it.created_at) as last_movement_at'),
                DB::raw("{$receivedValueSql} as total_received"),
                DB::raw("{$soldValueSql} as total_sold"),
                DB::raw('COALESCE(cs.shop_quantity, 0) as current_shop_stock'),
                DB::raw('COALESCE(cs.store_quantity, 0) as current_store_stock'),
            ]);

        if ($search = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('it.product_code', 'like', "%{$search}%")
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
