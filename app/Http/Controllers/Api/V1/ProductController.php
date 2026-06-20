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

    public function index(Request $request)
    {
        $query = $this->baseQuery($request)->whereNull('deleted_at');

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('product_code', 'like', "%{$q}%")
                    ->orWhere('product_name', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderBy('product_name')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $data = $request->validate($rules);

        if (empty($data['product_code'])) {
            $orgId = (int) ($request->user()?->organization_id ?? $data['organization_id'] ?? 0);
            $data['product_code'] = Product::generateNextProductCode($orgId);
        }

        if (empty($data['organization_id']) && $request->user()) {
            $data['organization_id'] = $request->user()->organization_id;
        }

        if ($request->user()) {
            $data['created_by'] = $request->user()->id;
        }

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
        $data = $request->validate($rules);
        if ($request->user()) {
            $data['updated_by'] = $request->user()->id;
        }
        $model->update($data);
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
