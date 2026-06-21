<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\SalePayment;
use Illuminate\Http\Request;

class SalePaymentController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return SalePayment::class;
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);

        if ($request->filled('sale_ids')) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('sale_ids')))));
            if ($ids !== []) {
                $query->whereIn('sale_id', $ids);
            }
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        $perPage = min((int) $request->input('per_page', 25), 500);

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }
}
