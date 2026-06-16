<?php

namespace App\Services\Inventory;

use App\Models\CurrentStock;
use App\Models\Product;
use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

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
        $globalThreshold = $system?->global_low_stock_threshold;
        $perPage = min((int) $request->input('per_page', 50), 200);

        $query = CurrentStock::query()
            ->join('products as p', 'p.product_code', '=', 'current_stock.product_code')
            ->where('p.organization_id', $organizationId)
            ->where('p.low_stock_alert_enabled', true)
            ->select([
                'current_stock.branch_id',
                'current_stock.product_code',
                'p.product_name',
                'current_stock.shop_quantity',
                'current_stock.store_quantity',
                'p.reorder_point',
            ])
            ->selectRaw('(current_stock.shop_quantity + current_stock.store_quantity) as total_quantity');

        if ($request->filled('branch_id')) {
            $query->where('current_stock.branch_id', $request->input('branch_id'));
        }

        if ($request->filled('product_code')) {
            $query->where('current_stock.product_code', $request->input('product_code'));
        }

        $query->where(function (Builder $inner) use ($mode, $globalThreshold) {
            $usePerProduct = in_array($mode, ['per_product', 'both'], true);
            $useGlobal = in_array($mode, ['global', 'both'], true) && $globalThreshold !== null;

            if ($usePerProduct) {
                $inner->whereRaw('(current_stock.shop_quantity + current_stock.store_quantity) <= p.reorder_point');
            }

            if ($useGlobal) {
                $method = $usePerProduct ? 'orWhereRaw' : 'whereRaw';
                $inner->{$method}(
                    '(current_stock.shop_quantity + current_stock.store_quantity) <= ?',
                    [(float) $globalThreshold],
                );
            }

            if (! $usePerProduct && ! $useGlobal) {
                $inner->whereRaw('1 = 0');
            }
        });

        $paginator = $query->orderBy('current_stock.product_code')->paginate($perPage);

        $paginator->getCollection()->transform(function ($row) use ($mode, $globalThreshold) {
            $total = (float) ($row->total_quantity ?? 0);
            $reorder = (float) ($row->reorder_point ?? 0);
            $alerts = [];

            if (in_array($mode, ['per_product', 'both'], true) && $total <= $reorder) {
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
                'reorder_point' => $reorder,
                'global_low_stock_threshold' => $globalThreshold !== null ? (float) $globalThreshold : null,
                'stock_alert_mode' => $mode,
                'product_alert' => 'REORDER',
                'alert_reasons' => $alerts,
            ];
        });

        return $paginator->toArray();
    }
}
