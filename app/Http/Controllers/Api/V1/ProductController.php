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
        $query = Product::query()->whereNull('deleted_at');

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        $q = trim((string) $request->input('q', ''));
        if ($q !== '') {
            $terms = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $query->where(function ($sub) use ($like) {
                    $sub->where('product_code', 'like', $like)
                        ->orWhere('product_name', 'like', $like);
                });
            }
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json(
            $query->orderBy('product_name')->paginate($perPage),
        );
    }

    /** GET /products/generate-code — unique 6-digit SKU */
    public function generateCode()
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $exists = Product::query()->where('product_code', $code)->exists();
            if (! $exists) {
                return response()->json(['code' => $code]);
            }
        }

        return response()->json(['message' => 'Could not generate a unique product code. Try again.'], 503);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $rules = [
            'product_code' => 'required|string|max:200',
            'product_name' => 'required|string|max:200',
            'subcategory_id' => 'required|integer',
            'unit_id' => 'required|integer',
            'unit_price' => 'required|numeric|min:0',
            'last_selling_price' => 'nullable|numeric|min:0',
            'last_cost_price' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_value' => 'nullable|numeric|min:0',
            'product_weight' => 'nullable|numeric|min:0',
            'stock_in_shop' => 'nullable|numeric|min:0',
            'stock_in_store' => 'nullable|numeric|min:0',
            'supplier_id' => 'nullable|integer',
            'sell_on_retail' => 'nullable|boolean',
            'vat_id' => 'nullable|integer',
            'organization_id' => 'nullable|integer',
            'reorder_point' => 'nullable|numeric|min:0',
            'created_by' => 'nullable|integer',
        ];
        $data = $request->validate($rules);
        $data = $this->normalizeDiscountFields($data);
        $data['organization_id'] = $data['organization_id'] ?? (int) $user->organization_id;
        $data['vat_id'] = $data['vat_id'] ?? (int) (\App\Models\Vat::query()->value('id') ?? 1);
        $data['created_by'] = (int) $user->id;
        $data['last_selling_price'] = $data['last_selling_price'] ?? $data['unit_price'];
        $data['stock_in_shop'] = $data['stock_in_shop'] ?? 0;
        $data['stock_in_store'] = $data['stock_in_store'] ?? 0;
        $data['reorder_point'] = $data['reorder_point'] ?? 0;
        $data['sell_on_retail'] = $data['sell_on_retail'] ?? false;

        $model = Product::create($data);

        $this->logPriceChange(
            $model,
            (float) $model->unit_price,
            (float) ($model->last_cost_price ?? 0),
            $this->effectiveDiscountPct($model),
            $request->user()
        );

        return response()->json($model, 201);
    }

    public function update(Request $request, string $id)
    {
        $model = Product::where($this->routeKeyColumn(), $id)->firstOrFail();
        $prevUnit = (float) $model->unit_price;
        $prevCost = (float) ($model->last_cost_price ?? 0);
        $prevDisc = $this->effectiveDiscountPct($model);

        $rules = [
            'product_code' => 'sometimes|string|max:200',
            'product_name' => 'sometimes|string|max:200',
            'subcategory_id' => 'sometimes|integer',
            'unit_id' => 'sometimes|integer',
            'unit_price' => 'sometimes|numeric|min:0',
            'last_selling_price' => 'nullable|numeric|min:0',
            'last_cost_price' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_value' => 'nullable|numeric|min:0',
            'product_weight' => 'nullable|numeric|min:0',
            'stock_in_shop' => 'nullable|numeric|min:0',
            'stock_in_store' => 'nullable|numeric|min:0',
            'supplier_id' => 'nullable|integer',
            'sell_on_retail' => 'nullable|boolean',
            'vat_id' => 'nullable|integer',
            'reorder_point' => 'nullable|numeric|min:0',
            'deleted_at' => 'nullable|date',
            'deleted_by' => 'nullable|integer',
        ];
        $data = $request->validate($rules);
        if (array_key_exists('discount_type', $data) || array_key_exists('discount_percentage', $data) || array_key_exists('discount_value', $data)) {
            $data = $this->normalizeDiscountFields(array_merge($model->only(['discount_type', 'discount_percentage', 'discount_value']), $data));
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

    public function destroy(string $id)
    {
        $user = request()->user();
        $model = Product::query()
            ->where($this->routeKeyColumn(), $id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $model->update([
            'deleted_at' => now(),
            'deleted_by' => (int) $user->id,
        ]);

        return response()->json(null, 204);
    }

    /** @param array<string, mixed> $data */
    protected function normalizeDiscountFields(array $data): array
    {
        $type = $data['discount_type'] ?? 'percentage';
        $data['discount_type'] = $type;

        if ($type === 'fixed') {
            $data['discount_value'] = (float) ($data['discount_value'] ?? 0);
            $data['discount_percentage'] = 0;
        } else {
            $data['discount_percentage'] = (float) ($data['discount_percentage'] ?? 0);
            $data['discount_value'] = 0;
        }

        return $data;
    }

    protected function effectiveDiscountPct(Product $product): float
    {
        if (($product->discount_type ?? 'percentage') === 'fixed') {
            return 0;
        }

        return (float) ($product->discount_percentage ?? 0);
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
        $disc = $this->effectiveDiscountPct($product);

        if ($unit === $prevUnit) {
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
