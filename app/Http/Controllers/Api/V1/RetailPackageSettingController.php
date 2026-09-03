<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesViaParentOrganization;
use App\Models\RetailPackageSetting;
use App\Models\SubCategory;
use App\Services\Catalog\CatalogPricingBroadcastService;
use Illuminate\Http\Request;

class RetailPackageSettingController extends BaseResourceController
{
    use ScopesViaParentOrganization;

    protected function modelClass(): string
    {
        return RetailPackageSetting::class;
    }

    protected function parentOrganizationScope(): array
    {
        return ['relation' => 'product'];
    }

    protected function settingsQuery(Request $request)
    {
        return $this->baseQuery($request)->with([
            'product:product_code,product_name,unit_id,subcategory_id',
        ]);
    }

    protected function presentSetting(RetailPackageSetting $setting): array
    {
        $payload = $setting->toArray();
        $product = $setting->relationLoaded('product') ? $setting->product : null;
        $payload['product_name'] = $product?->product_name;
        $payload['product_unit_id'] = $product?->unit_id;
        $payload['product_subcategory_id'] = $product?->subcategory_id;

        return $payload;
    }

    public function index(Request $request)
    {
        $query = $this->settingsQuery($request);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('product_code', 'like', "%{$q}%")
                    ->orWhereHas('product', fn ($product) => $product->where('product_name', 'like', "%{$q}%"));
            });
        }

        $productCodes = $request->input('product_codes');
        if (is_string($productCodes) && trim($productCodes) !== '') {
            $codes = array_values(array_filter(array_map('trim', explode(',', $productCodes))));
            if ($codes !== []) {
                $query->whereIn('product_code', array_slice($codes, 0, 200));
            }
        } elseif (is_array($productCodes) && $productCodes !== []) {
            $codes = array_values(array_filter(array_map('strval', $productCodes)));
            if ($codes !== []) {
                $query->whereIn('product_code', array_slice($codes, 0, 200));
            }
        }

        $subcategoryId = (int) $request->input('subcategory_id', 0);
        $categoryId = (int) $request->input('category_id', 0);
        if ($subcategoryId > 0) {
            $query->whereHas('product', fn ($product) => $product->where('subcategory_id', $subcategoryId));
        } elseif ($categoryId > 0) {
            // Parent category (unlike product list, which treats category_id as subcategory).
            $subIds = SubCategory::query()
                ->where('category_id', $categoryId)
                ->pluck('id')
                ->all();
            if ($subIds === []) {
                $query->whereRaw('0 = 1');
            } else {
                $query->whereHas('product', fn ($product) => $product->whereIn('subcategory_id', $subIds));
            }
        }

        $perPage = min((int) $request->input('per_page', 25), 500);
        $this->applyListOrdering($request, $query, 'id', 'desc');

        return response()->json(
            $query->paginate($perPage)->through(
                fn (RetailPackageSetting $row) => $this->presentSetting($row),
            ),
        );
    }

    public function show(Request $request, string $id, ?string $nestedId = null)
    {
        $model = $this->settingsQuery($request)
            ->where($this->routeKeyColumn(), $this->resolveResourceId($id, $nestedId))
            ->firstOrFail();

        return response()->json($this->presentSetting($model));
    }

    public function store(Request $request)
    {
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $data = $request->validate($rules);
        $productCode = trim((string) ($data['product_code'] ?? ''));
        if ($productCode === '') {
            return response()->json([
                'message' => 'The product code field is required.',
                'errors' => ['product_code' => ['The product code field is required.']],
            ], 422);
        }
        $data['product_code'] = $productCode;

        // One retail package row per product_code (uq_product_code). Upsert so
        // product form / create races do not throw Duplicate entry 1062.
        $existing = $this->settingsQuery($request)
            ->where('product_code', $productCode)
            ->first();
        $prevMarkup = $existing ? (float) ($existing->markup_price ?? 0) : 0;
        $wasExisting = $existing !== null;

        $model = RetailPackageSetting::query()->updateOrCreate(
            ['product_code' => $productCode],
            $data,
        );
        $model = $this->settingsQuery($request)->find($model->id) ?? $model;

        $user = $request->user();
        if ($user && $this->auditable()) {
            $this->auditLogger()->logModel(
                $user,
                $wasExisting ? 'update' : 'create',
                $model,
                $wasExisting ? $existing?->getAttributes() : null,
                $model->getAttributes(),
                $request,
            );
        }

        $nextMarkup = (float) ($model->markup_price ?? 0);
        if (! $wasExisting || $nextMarkup !== $prevMarkup) {
            $this->broadcastMarkupChanged($model, $prevMarkup, $nextMarkup);
        }

        return response()->json($this->presentSetting($model), $wasExisting ? 200 : 201);
    }

    public function update(Request $request, string $id, ?string $nestedId = null)
    {
        $settingId = $this->resolveResourceId($id, $nestedId);
        $existing = $this->settingsQuery($request)->find($settingId);
        $prevMarkup = $existing ? (float) ($existing->markup_price ?? 0) : 0;

        $response = parent::update($request, $id, $nestedId);
        $payload = $response->getData(true);
        $resolvedId = $payload['id'] ?? $settingId;

        $model = $this->settingsQuery($request)->find($resolvedId);
        if (! $model) {
            return $response;
        }

        $nextMarkup = (float) ($model->markup_price ?? 0);
        if ($nextMarkup !== $prevMarkup) {
            $this->broadcastMarkupChanged($model, $prevMarkup, $nextMarkup);
        }

        return response()->json($this->presentSetting($model));
    }

    protected function broadcastMarkupChanged(
        RetailPackageSetting $model,
        float $previousMarkupPrice,
        float $newMarkupPrice,
    ): void {
        $product = $model->relationLoaded('product')
            ? $model->product
            : $model->product()->first();
        $orgId = (int) ($product?->organization_id ?? 0);
        if ($orgId <= 0) {
            return;
        }

        app(CatalogPricingBroadcastService::class)->notifyMarkupChanged(
            $orgId,
            (string) $model->product_code,
            $product?->product_name,
            request()->user()?->id,
            $previousMarkupPrice,
            $newMarkupPrice,
        );
    }
}
