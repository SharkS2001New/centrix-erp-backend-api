<?php

namespace App\Services\Inventory;

use App\Models\SystemSetting;
use App\Services\Catalog\ProductCatalogFilterService;
use App\Support\StockReportScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LowStockReportService
{
    public function __construct(protected BranchStockService $branchStock) {}

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
                'u.conversion_factor',
                'u.small_packaging_label',
                'u.middle_packaging_label',
                'u.middle_factor',
                'u.uom_type',
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

        $belowReorderCase = match (true) {
            $mode === 'global' && $globalThreshold !== null => "CASE WHEN {$totalSql} > 0 AND {$totalSql} <= ".(float) $globalThreshold.' THEN 1 ELSE 0 END',
            $mode === 'global' => '0',
            $mode === 'both' && $globalThreshold !== null => "CASE WHEN {$totalSql} > 0 AND ("
                .' (p.reorder_point > 0 AND '.$totalSql.' <= p.reorder_point)'
                .' OR ('.$totalSql.' <= '.(float) $globalThreshold.')'
                .') THEN 1 ELSE 0 END',
            default => "CASE WHEN {$totalSql} > 0 AND p.reorder_point > 0 AND {$totalSql} <= p.reorder_point THEN 1 ELSE 0 END",
        };

        $summaryRaw = (clone $query)
            ->reorder()
            ->select([
                DB::raw('COUNT(*) as row_count'),
                DB::raw("SUM(CASE WHEN {$totalSql} <= 0 THEN 1 ELSE 0 END) as out_of_stock_count"),
                DB::raw("SUM({$belowReorderCase}) as below_reorder_count"),
            ])
            ->first();

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
                'conversion_factor' => (float) ($row->conversion_factor ?? 1),
                'small_packaging_label' => $row->small_packaging_label,
                'middle_packaging_label' => $row->middle_packaging_label,
                'middle_factor' => $row->middle_factor !== null ? (float) $row->middle_factor : null,
                'uom_type' => $row->uom_type,
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

        $payload = $paginator->toArray();
        $payload['data'] = $this->branchStock->attachAvailabilityToRows(
            array_map(
                fn ($row) => is_array($row) ? $row : (array) $row,
                $payload['data'] ?? [],
            ),
        );

        foreach ($payload['data'] as &$row) {
            $available = (float) ($row['available_total_units'] ?? 0);
            $row['total_quantity'] = $available;
            $row['total_base_units'] = $available;
        }
        unset($row);

        $payload['summary'] = [
            'row_count' => (int) ($summaryRaw->row_count ?? $payload['total'] ?? 0),
            'out_of_stock_count' => (int) ($summaryRaw->out_of_stock_count ?? 0),
            'below_reorder_count' => (int) ($summaryRaw->below_reorder_count ?? 0),
        ];

        return $payload;
    }
}
