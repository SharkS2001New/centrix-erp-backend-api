<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\SaleItem;
use Illuminate\Http\Request;

class SaleItemController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return SaleItem::class;
    }

    public function index(Request $request)
    {
        $query = SaleItem::query()->with(['sale.cashier']);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = $request->input('q')) {
            $searchCol = $this->fillableFields()[0] ?? 'id';
            $query->where($searchCol, 'like', "%{$q}%");
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json(
            $query->orderByDesc('id')->paginate($perPage)
        );
    }
}
