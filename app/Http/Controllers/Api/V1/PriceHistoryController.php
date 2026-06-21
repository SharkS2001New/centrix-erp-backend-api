<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PriceHistory;
use Illuminate\Http\Request;

class PriceHistoryController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return PriceHistory::class;
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'product_code' => 'required|string|exists:products,product_code',
            'unit_price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
            'discount_pct' => 'nullable|numeric|min:0',
            'changed_at' => 'nullable|date',
        ]);

        $row = PriceHistory::create([
            'product_code' => $data['product_code'],
            'unit_price' => $data['unit_price'],
            'cost_price' => $data['cost_price'],
            'discount_pct' => $data['discount_pct'] ?? 0,
            'changed_by' => $user->id,
            'organization_id' => $user->organization_id,
            'changed_at' => $data['changed_at'] ?? now(),
        ]);

        return response()->json($row, 201);
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request)
            ->with(['product.subcategory', 'changedByUser:id,username,full_name']);

        if ($days = (int) $request->input('days', 0)) {
            $query->where('changed_at', '>=', now()->subDays(max(1, $days))->startOfDay());
        }

        if ($request->filled('changed_by')) {
            $query->where('changed_by', $request->input('changed_by'));
        }

        if ($request->filled('category_id')) {
            $categoryId = (int) $request->input('category_id');
            $query->whereHas('product.subcategory', fn ($sub) => $sub->where('category_id', $categoryId));
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('product_code', 'like', "%{$q}%")
                    ->orWhereHas('product', fn ($product) => $product->where('product_name', 'like', "%{$q}%"));
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json(
            $query->orderByDesc('changed_at')->paginate($perPage),
        );
    }

    public function update(Request $request, string $id)
    {
        $model = PriceHistory::findOrFail($id);
        $data = $request->validate([
            'product_code' => 'sometimes|string|exists:products,product_code',
            'unit_price' => 'sometimes|numeric|min:0',
            'cost_price' => 'sometimes|numeric|min:0',
            'discount_pct' => 'nullable|numeric|min:0',
            'changed_at' => 'nullable|date',
        ]);

        $model->update($data);

        return response()->json($model);
    }
}
