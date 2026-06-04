<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Sale;
use Illuminate\Http\Request;

class SaleController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Sale::class;
    }

    public function show(string $id)
    {
        $sale = Sale::with(['items.product'])->findOrFail($id);

        return response()->json($sale);
    }

    public function index(Request $request)
    {
        $query = Sale::query();

        if ($request->boolean('with_items')) {
            $query->with(['items.product']);
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = $request->input('q')) {
            $query->where(function ($sub) use ($q) {
                $sub->where('order_num', 'like', "%{$q}%")
                    ->orWhere('customer_name_override', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }
}
