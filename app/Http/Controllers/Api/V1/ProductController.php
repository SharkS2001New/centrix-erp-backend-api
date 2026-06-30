<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\SubCategory;
use App\Services\Catalog\ProductCatalogScopeService;
use App\Services\Inventory\BranchStockService;
use App\Services\Inventory\OpeningStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProductController extends BaseResourceController
{
    public function __construct(
        protected ProductCatalogScopeService $catalogScope,
        protected BranchStockService $branchStock,
        protected OpeningStockService $openingStock,
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
    protected function presentProduct(
        Product $product,
        ?Request $request = null,
        bool $skipBranchOverlay = false,
    ): array {
        $data = array_merge($product->toArray(), [
            'catalog_scope' => $this->catalogScope->catalogScopeForProduct($product),
        ]);

        if ($skipBranchOverlay || ! $request) {
            return $data;
        }

        $branchId = $this->branchStock->resolveBranchIdOptional($request->user(), $request);
        if ($branchId !== null) {
            $data = $this->branchStock->overlayPayload($data, $branchId);
        }

        return $data;
    }

    protected function sortableColumns(): array
    {
        return [
            'product_code',
            'product_name',
            'unit_price',
            'last_cost_price',
            'discount_percentage',
            'discount_value',
            'product_weight',
            'reorder_point',
            'created_at',
        ];
    }

    public function index(Request $request)
    {
        $query = Product::query();
        $user = $request->user();
        if ($user) {
            $this->catalogScope->scopeForUser($query, $user, $request);
        }

        $status = (string) $request->input('status', 'active');
        if ($status === 'inactive') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if ($val === null || $val === '') {
                continue;
            }
            if ($col === 'catalog_scope') {
                if ($val === 'organization') {
                    $query->whereNull('branch_id');
                } elseif ($val === 'branch') {
                    $query->whereNotNull('branch_id');
                }
                continue;
            }
            if ($col === 'category_id') {
                $subIds = SubCategory::query()
                    ->where('category_id', (int) $val)
                    ->pluck('id');
                if ($subIds->isEmpty()) {
                    $query->whereRaw('0 = 1');
                } else {
                    $query->whereIn('subcategory_id', $subIds);
                }
                continue;
            }
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($pricing = (string) $request->input('pricing', '')) {
            if ($pricing === 'retail') {
                $query->where('sell_on_retail', true);
            } elseif ($pricing === 'wholesale') {
                $query->where(function ($inner) {
                    $inner->where('sell_on_retail', false)->orWhereNull('sell_on_retail');
                });
            }
        }

        if ($stockStatus = (string) $request->input('stock_status', '')) {
            $branchIdForFilter = $this->branchStock->resolveBranchIdOptional($user, $request);
            $this->branchStock->applyStockStatusFilter($query, $stockStatus, $branchIdForFilter);
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('product_code', 'like', "%{$q}%")
                    ->orWhere('product_name', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $this->applyListOrdering($request, $query, 'product_name', 'asc');
        $paginator = $query
            ->select('products.*')
            ->with('branch:id,branch_code,branch_name')
            ->paginate($perPage);

        $branchId = $this->branchStock->resolveBranchIdOptional($user, $request);
        $presented = $paginator->getCollection()->map(
            fn (Product $product) => $this->presentProduct($product, $request, skipBranchOverlay: $branchId !== null),
        );

        if ($branchId !== null) {
            $presented = $this->branchStock->overlayCollection($presented, $branchId);
        }

        $paginator->setCollection($presented);

        return response()->json($paginator);
    }

    /** GET /products/catalog-summary */
    public function catalogSummary(Request $request)
    {
        $query = Product::query()->whereNull('deleted_at');
        $user = $request->user();
        if ($user) {
            $this->catalogScope->scopeForUser($query, $user, $request);
        }

        $branchId = $this->branchStock->resolveBranchIdOptional($user, $request);

        $total = (clone $query)->count();

        $outQuery = clone $query;
        $this->branchStock->applyStockStatusFilter($outQuery, 'out_of_stock', $branchId);
        $outOfStock = $outQuery->count();

        $lowQuery = clone $query;
        $this->branchStock->applyStockStatusFilter($lowQuery, 'low_stock', $branchId);
        $lowStock = $lowQuery->count();

        return response()->json([
            'total' => $total,
            'active' => $total,
            'low_stock' => $lowStock,
            'out_of_stock' => $outOfStock,
            'branch_id' => $branchId,
        ]);
    }

    public function store(Request $request)
    {
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $rules['catalog_scope'] = 'nullable|in:organization,branch';
        $rules['branch_id'] = 'nullable|integer|exists:branches,id';
        $rules['opening_stock'] = 'nullable|array';
        $rules['opening_stock.branch_id'] = 'required_with:opening_stock|integer|exists:branches,id';
        $rules['opening_stock.shop_quantity'] = 'nullable|numeric|min:0';
        $rules['opening_stock.store_quantity'] = 'nullable|numeric|min:0';
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

        $openingStock = $data['opening_stock'] ?? null;
        unset($data['opening_stock'], $data['stock_in_shop'], $data['stock_in_store']);

        if ($openingStock !== null) {
            $shopQty = (float) ($openingStock['shop_quantity'] ?? 0);
            $storeQty = (float) ($openingStock['store_quantity'] ?? 0);
            if ($shopQty <= 0 && $storeQty <= 0) {
                $openingStock = null;
            }
        }

        $user = $request->user();

        $model = DB::transaction(function () use ($data, $openingStock, $user) {
            $model = Product::create($data);

            if ($openingStock !== null) {
                if (! $user) {
                    throw new InvalidArgumentException('Opening stock requires an authenticated user.');
                }

                $this->openingStock->applyOnProductCreate(
                    $user,
                    (string) $model->product_code,
                    (int) $model->id,
                    $openingStock,
                );
            }

            $this->logPriceChange(
                $model,
                (float) $model->unit_price,
                (float) ($model->last_cost_price ?? 0),
                (float) ($model->discount_percentage ?? 0),
                $user,
            );

            return $model;
        });

        return response()->json($this->presentProduct($model->load('branch:id,branch_code,branch_name'), $request), 201);
    }

    public function show(Request $request, string $id)
    {
        $product = $this->resolveProductForRequest($request, $id);

        return response()->json($this->presentProduct($product->load('branch:id,branch_code,branch_name'), $request));
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

        unset($data['stock_in_shop'], $data['stock_in_store']);

        $model->update($data);
        $model->refresh();

        $this->logPriceChangeIfChanged(
            $model,
            $prevUnit,
            $prevCost,
            $prevDisc,
            $request->user()
        );

        return response()->json($this->presentProduct($model->load('branch:id,branch_code,branch_name'), $request));
    }

    public function destroy(Request $request, string $id)
    {
        $model = $this->findScopedProduct($request, $id);

        if ($request->user()) {
            $model->forceFill(['deleted_by' => $request->user()->id])->save();
        }

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

    protected function resolveProductForRequest(Request $request, string $id): Product
    {
        $user = $request->user();
        if ($user) {
            $orgId = (int) ($this->access()->organizationId($user, $request) ?? $user->organization_id ?? 0);
            $branchId = $this->branchStock->resolveBranchIdOptional($user, $request);
            if ($orgId > 0 && $branchId !== null) {
                return $this->catalogScope->findAccessibleProduct($id, $orgId, $branchId);
            }
        }

        return $this->findScopedProduct($request, $id);
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
