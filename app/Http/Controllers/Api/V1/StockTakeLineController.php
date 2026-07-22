<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesViaParentOrganization;
use App\Models\StockTakeLine;
use Illuminate\Http\Request;

class StockTakeLineController extends BaseResourceController
{
    use ScopesViaParentOrganization;

    protected function modelClass(): string
    {
        return StockTakeLine::class;
    }

    protected function parentOrganizationScope(): array
    {
        return ['relation' => 'session', 'column' => 'organization_id'];
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request)
            ->leftJoin('stock_take_sessions as sts', 'sts.id', '=', 'stock_take_lines.session_id')
            ->leftJoin('branches as b', 'b.id', '=', 'sts.branch_id')
            ->leftJoin('products as p', function ($join) {
                $join->on('p.product_code', '=', 'stock_take_lines.product_code')
                    ->on('p.organization_id', '=', 'b.organization_id')
                    ->whereNull('p.deleted_at');
            })
            ->leftJoin('uoms as u', 'u.id', '=', 'p.unit_id')
            ->select([
                'stock_take_lines.*',
                'p.product_name',
                'p.unit_id',
                'p.subcategory_id',
                'u.full_name as uom_name',
                'u.conversion_factor',
                'u.small_packaging_label',
                'u.middle_packaging_label',
                'u.middle_factor',
                'u.uom_type',
            ]);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if ($col === 'subcategory_id' || $col === 'category_id') {
                continue;
            }
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where('stock_take_lines.'.$col, $val);
            }
        }

        $subcategoryId = (int) ($request->input('filter.subcategory_id') ?: $request->input('subcategory_id') ?: 0);
        if ($subcategoryId > 0) {
            $query->where('p.subcategory_id', $subcategoryId);
        }

        $categoryId = (int) ($request->input('filter.category_id') ?: $request->input('category_id') ?: 0);
        if ($categoryId > 0) {
            $query->whereIn('p.subcategory_id', function ($sub) use ($categoryId) {
                $sub->select('id')
                    ->from('sub_categories')
                    ->where('category_id', $categoryId);
            });
        }

        if ($q = $request->input('q')) {
            $query->where(function ($inner) use ($q) {
                $inner->where('stock_take_lines.product_code', 'like', "%{$q}%")
                    ->orWhere('p.product_name', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $this->applyListOrdering($request, $query, 'p.product_name', 'asc');
        $query->orderBy('stock_take_lines.stock_location');

        return response()->json($query->paginate($perPage));
    }
}
