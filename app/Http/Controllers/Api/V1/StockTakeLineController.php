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
        return ['relation' => 'session', 'via_branch' => true];
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
            ->select('stock_take_lines.*', 'p.product_name');

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where('stock_take_lines.'.$col, $val);
            }
        }

        if ($q = $request->input('q')) {
            $query->where(function ($inner) use ($q) {
                $inner->where('stock_take_lines.product_code', 'like', "%{$q}%")
                    ->orWhere('p.product_name', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $this->applyListOrdering($request, $query, 'stock_take_lines.id', 'desc');

        return response()->json($query->paginate($perPage));
    }
}
