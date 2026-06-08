<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PriceHistory;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Product::class;
    }

    protected function routeKeyColumn(): string
    {
        return 'product_code';
    }

    public function store(Request $request)
    {
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $data = $request->validate($rules);
        $model = Product::create($data);

        $this->logPriceChange(
            $model,
            (float) $model->unit_price,
            (float) ($model->last_cost_price ?? 0),
            (float) ($model->discount_percentage ?? 0),
            $request->user()
        );

        return response()->json($model, 201);
    }

    public function update(Request $request, string $id)
    {
        $model = Product::where($this->routeKeyColumn(), $id)->firstOrFail();
        $prevUnit = (float) $model->unit_price;
        $prevCost = (float) ($model->last_cost_price ?? 0);
        $prevDisc = (float) ($model->discount_percentage ?? 0);

        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $model->update($request->validate($rules));
        $model->refresh();

        $this->logPriceChangeIfChanged(
            $model,
            $prevUnit,
            $prevCost,
            $prevDisc,
            $request->user()
        );

        return response()->json($model);
    }

    protected function logPriceChangeIfChanged(
        Product $product,
        float $prevUnit,
        float $prevCost,
        float $prevDisc,
        $user
    ): void {
        $unit = (float) $product->unit_price;
        $cost = (float) ($product->last_cost_price ?? 0);
        $disc = (float) ($product->discount_percentage ?? 0);

        if ($unit === $prevUnit && $cost === $prevCost && $disc === $prevDisc) {
            return;
        }

        $this->logPriceChange($product, $unit, $cost, $disc, $user);
    }

    protected function logPriceChange(
        Product $product,
        float $unitPrice,
        float $costPrice,
        float $discountPct,
        $user
    ): void {
        PriceHistory::create([
            'product_code' => $product->product_code,
            'unit_price' => $unitPrice,
            'cost_price' => $costPrice,
            'discount_pct' => $discountPct,
            'changed_by' => $user->id,
            'organization_id' => $product->organization_id ?? $user->organization_id,
            'changed_at' => now(),
        ]);
    }
}
