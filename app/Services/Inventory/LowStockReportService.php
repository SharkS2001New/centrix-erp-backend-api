<?php

namespace App\Services\Inventory;

use App\Models\SystemSetting;
use App\Services\Catalog\ProductCatalogFilterService;
use App\Support\StockReportScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LowStockReportService
{
    /** @return array<string, mixed> */
    public function paginate(Request $request, int $organizationId): array
    {
        $system = SystemSetting::query()
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->first();

        $mode = $system?->stock_alert_mode ?? 'per_product';
        $globalThreshold = $system?->global_low_stock_threshold !== null
            ? (float) $system->global_low_stock_threshold
            : null;
        $perPage = min((int) $request->input('per_page', 50), 200);
        $branchId = StockReportScope::resolveBranchId($request, $organizationId);

        $totalSql = '(COALESCE(cs.shop_quantity, 0) + COALESCE(cs.store_quantity, 0))';

        $query = DB::table('products as p')
            ->join('uoms as u', 'u.id', '=', 'p.unit_id')
            ->join('branches as br', function ($join) use ($organizationId, $branchId) {
                $join->where('br.organization_id', '=', $organizationId)
                    ->where('br.id', '=', $branchId);
            })
            ->leftJoin('current_stock as cs', function ($join) {
                $join->on('cs.product_code', '=', 'p.product_code')
                    ->on('cs.branch_id', '=', 'br.id');
            })
            ->where('p.organization_id', $organizationId)
            ->whereNull('p.deleted_at')
            ->when($request->filled('product_code'), fn ($q) => $q->where('p.product_code', $request->input('product_code')))
            ->when(
                ($subcategoryId = ProductCatalogFilterService::resolveSubcategoryFilterId($request)) !== null,
                fn ($q) => $q->where('p.subcategory_id', $subcategoryId),
            )
            ->select([
                DB::raw("{$branchId} as branch_id"),
                'p.product_code',
                'p.product_name',
                'u.full_name as uom_name',
                DB::raw('COALESCE(cs.shop_quantity, 0) as shop_quantity'),
                DB::raw('COALESCE(cs.store_quantity, 0) as store_quantity'),
                DB::raw("{$totalSql} as total_quantity"),
                DB::raw("{$totalSql} as total_base_units"),
                'p.reorder_point',
            ]);

        $query->where(function ($outer) use ($mode, $globalThreshold, $totalSql) {
            $outer->whereRaw("{$totalSql} <= 0");

            $outer->orWhere(function ($inner) use ($mode, $globalThreshold, $totalSql) {
                $inner->where('p.low_stock_alert_enabled', true)
                    ->where(function ($alerts) use ($mode, $globalThreshold, $totalSql) {
                        if (in_array($mode, ['per_product', 'both'], true)) {
                            $alerts->where(function ($q) use ($totalSql) {
                                $q->where('p.reorder_point', '>', 0)
                                    ->whereRaw("{$totalSql} > 0")
                                    ->whereRaw("{$totalSql} <= p.reorder_point");
                            });
                        }

                        if (in_array($mode, ['global', 'both'], true) && $globalThreshold !== null) {
                            $alerts->orWhere(function ($q) use ($totalSql, $globalThreshold) {
                                $q->whereRaw("{$totalSql} > 0")
                                    ->whereRaw("{$totalSql} <= ?", [$globalThreshold]);
                            });
                        }
                    });
            });
        });

        $paginator = $query->orderBy('p.product_name')->paginate($perPage);

        $paginator->getCollection()->transform(function ($row) use ($mode, $globalThreshold) {
            $total = (float) ($row->total_quantity ?? 0);
            $reorder = (float) ($row->reorder_point ?? 0);
            $alerts = [];

            if ($total <= 0) {
                $alerts[] = 'out_of_stock';
            }

            if (in_array($mode, ['per_product', 'both'], true) && $reorder > 0 && $total > 0 && $total <= $reorder) {
                $alerts[] = 'reorder_point';
            }

            if (in_array($mode, ['global', 'both'], true)
                && $globalThreshold !== null
                && $total <= (float) $globalThreshold) {
                $alerts[] = 'global_threshold';
            }

            return [
                'branch_id' => (int) $row->branch_id,
                'product_code' => $row->product_code,
                'product_name' => $row->product_name,
                'uom_name' => $row->uom_name,
                'shop_quantity' => (float) $row->shop_quantity,
                'store_quantity' => (float) $row->store_quantity,
                'total_quantity' => $total,
                'total_base_units' => $total,
                'reorder_point' => $reorder,
                'global_low_stock_threshold' => $globalThreshold,
                'stock_alert_mode' => $mode,
                'product_alert' => 'REORDER',
                'alert_reasons' => $alerts,
            ];
        });

        return $paginator->toArray();
    }
}
