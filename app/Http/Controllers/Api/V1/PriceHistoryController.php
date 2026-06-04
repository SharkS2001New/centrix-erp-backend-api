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
