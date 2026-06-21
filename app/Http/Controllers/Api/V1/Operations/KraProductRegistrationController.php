<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Catalog\ProductCatalogScopeService;
use App\Services\Erp\ErpContext;
use App\Services\Kra\KraDeviceService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KraProductRegistrationController extends Controller
{
    public function __construct(protected ErpContext $erp) {}

    /** POST /api/v1/kra/register-products */
    public function register(Request $request)
    {
        $data = $request->validate([
            'product_codes' => 'sometimes|array|min:1',
            'product_codes.*' => 'string|max:50',
            'all' => 'sometimes|boolean',
        ]);

        $gate = $this->erp->gateForUser($request->user());
        $finance = $gate->moduleSettings('finance');

        if (empty($finance['enable_kra_device'])) {
            throw ValidationException::withMessages([
                'kra' => 'KRA fiscal device is not enabled for this organization.',
            ]);
        }

        $hasCodes = ! empty($data['product_codes']);
        $registerAll = ! empty($data['all']);

        if (! $hasCodes && ! $registerAll) {
            throw ValidationException::withMessages([
                'product_codes' => 'Provide product_codes or set all=true.',
            ]);
        }

        $query = Product::query()->whereNull('deleted_at');
        app(ProductCatalogScopeService::class)->scopeForUser($query, $request->user(), $request);
        if ($hasCodes) {
            $query->whereIn('product_code', $data['product_codes']);
        }

        $products = $query->orderBy('product_name')->get();
        if ($products->isEmpty()) {
            throw ValidationException::withMessages([
                'product_codes' => 'No matching active products found.',
            ]);
        }

        $path = trim((string) ($finance['kra_plu_register_path'] ?? '/api/register-plu'));
        $service = KraDeviceService::fromSettings($finance);
        $result = $service->registerProducts($products->all(), $path);

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
