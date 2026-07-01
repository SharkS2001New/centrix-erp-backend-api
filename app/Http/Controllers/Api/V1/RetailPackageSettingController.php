<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesViaParentOrganization;
use App\Models\RetailPackageSetting;
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

        $perPage = min((int) $request->input('per_page', 25), 200);
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
        $response = parent::store($request);
        $payload = $response->getData(true);
        $id = $payload['id'] ?? null;
        if (! $id) {
            return $response;
        }

        $model = $this->settingsQuery($request)->find($id);
        if (! $model) {
            return $response;
        }

        return response()->json($this->presentSetting($model), 201);
    }

    public function update(Request $request, string $id, ?string $nestedId = null)
    {
        $response = parent::update($request, $id, $nestedId);
        $payload = $response->getData(true);
        $settingId = $payload['id'] ?? $this->resolveResourceId($id, $nestedId);

        $model = $this->settingsQuery($request)->find($settingId);
        if (! $model) {
            return $response;
        }

        return response()->json($this->presentSetting($model));
    }
}
