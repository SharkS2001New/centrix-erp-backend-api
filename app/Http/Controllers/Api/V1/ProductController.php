<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PriceHistory;
use App\Models\Product;
use App\Services\Catalog\ProductCatalogScopeService;
use Illuminate\Http\Request;

class ProductController extends BaseResourceController
{
    public function __construct(
        protected ProductCatalogScopeService $catalogScope,
    ) {}

    protected function modelClass(): string
    {
        return Product::class;
    }

    protected function routeKeyColumn(): string
    {
        return 'product_code';
    }

    protected function scopesByOrganization(): bool
    {
        return false;
    }

    protected function scopesByBranch(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    protected function presentProduct(Product $product): array
    {
        return array_merge($product->toArray(), [
            'catalog_scope' => $this->catalogScope->catalogScopeForProduct($product),
        ]);
    }

    public function index(Request $request)
    {
        $query = Product::query()->whereNull('deleted_at');
        $user = $request->user();
        if ($user) {
            $this->catalogScope->scopeForUser($query, $user, $request);
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if ($col === 'catalog_scope') {
                if ($val === 'organization') {
                    $query->whereNull('branch_id');
                } elseif ($val === 'branch') {
                    $query->whereNotNull('branch_id');
                }
                continue;
            }
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
        $paginator = $query->with('branch:id,branch_code,branch_name')->orderBy('product_name')->paginate($perPage);
        $paginator->getCollection()->transform(fn (Product $product) => $this->presentProduct($product));

        return response()->json($paginator);
    }

    public function store(Request $request)
    {
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $rules['catalog_scope'] = 'nullable|in:organization,branch';
        $rules['branch_id'] = 'nullable|integer|exists:branches,id';
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
            $data = $this->catalogScope->normalizeWriteData($request->user(), $data);
        }

        $model = Product::create($data);

        $this->logPriceChange(
            $model,
            (float) $model->unit_price,
            (float) ($model->last_cost_price ?? 0),
            (float) ($model->discount_percentage ?? 0),
            $request->user()
        );

        return response()->json($this->presentProduct($model->load('branch:id,branch_code,branch_name')), 201);
    }

    public function show(Request $request, string $id)
    {
        return response()->json($this->presentProduct($this->findScopedProduct($request, $id)));
    }

    public function update(Request $request, string $id)
    {
        $model = $this->findScopedProduct($request, $id);
        $prevUnit = (float) $model->unit_price;
        $prevCost = (float) ($model->last_cost_price ?? 0);
        $prevDisc = (float) ($model->discount_percentage ?? 0);

        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $rules['catalog_scope'] = 'nullable|in:organization,branch';
        $rules['branch_id'] = 'nullable|integer|exists:branches,id';
        $data = $request->validate($rules);
        unset($data['organization_id']);

        if ($request->user()) {
            $data['updated_by'] = $request->user()->id;
            if (array_key_exists('catalog_scope', $data) || array_key_exists('branch_id', $data)) {
                $data = $this->catalogScope->normalizeWriteData($request->user(), $data, $model);
            }
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

        return response()->json($this->presentProduct($model->load('branch:id,branch_code,branch_name')));
    }

    public function destroy(Request $request, string $id)
    {
        $model = $this->findScopedProduct($request, $id);
        $model->delete();

        return response()->json(null, 204);
    }

    protected function findScopedProduct(Request $request, string $id): Product
    {
        $query = Product::query()->where($this->routeKeyColumn(), $id)->whereNull('deleted_at');
        $user = $request->user();
        if ($user) {
            $this->catalogScope->scopeForUser($query, $user, $request);
        }

        return $query->firstOrFail();
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
