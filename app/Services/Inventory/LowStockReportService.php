<?php

namespace App\Services\Inventory;

use App\Models\SystemSetting;
use App\Services\Auth\UserAccessService;
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

        $query = DB::table('products as p')
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
            ->when($request->filled('product_code'), fn ($q) => $q->where('p.product_code', $request->input('product_code')))
            ->select([
                'br.id as branch_id',
                'p.product_code',
                'p.product_name',
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

        $paginator = $query->orderBy('p.product_code')->paginate($perPage);

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
