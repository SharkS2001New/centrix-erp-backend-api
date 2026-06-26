<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Jobs\RegisterKraProductsJob;
use App\Models\Product;
use App\Services\Background\BackgroundTaskService;
use App\Services\Catalog\ProductCatalogScopeService;
use App\Services\Erp\ErpContext;
use App\Services\Kra\KraDeviceFailure;
use App\Services\Kra\KraDeviceService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KraProductRegistrationController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected BackgroundTaskService $tasks,
    ) {}

    /** POST /api/v1/kra/register-products */
    public function register(Request $request)
    {
        $data = $request->validate([
            'product_codes' => 'sometimes|array|min:1',
            'product_codes.*' => 'string|max:50',
            'all' => 'sometimes|boolean',
            'sync' => 'sometimes|boolean',
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

        if ($query->count() === 0) {
            throw ValidationException::withMessages([
                'product_codes' => 'No matching active products found.',
            ]);
        }

        if ($request->boolean('sync')) {
            return $this->registerSynchronously($request, $finance, $hasCodes, $registerAll);
        }

        $task = $this->tasks->create('kra_product_registration', $request->user(), [
            'product_codes' => $data['product_codes'] ?? [],
            'all' => $registerAll,
        ]);

        RegisterKraProductsJob::dispatch($task->id);

        return response()->json([
            'message' => 'KRA product registration queued.',
            'task_id' => $task->id,
            'queued' => true,
        ], 202);
    }

    /** @param array<string, mixed> $finance */
    protected function registerSynchronously(
        Request $request,
        array $finance,
        bool $hasCodes,
        bool $registerAll,
    ) {
        $query = Product::query()->whereNull('deleted_at');
        app(ProductCatalogScopeService::class)->scopeForUser($query, $request->user(), $request);
        if ($hasCodes) {
            $query->whereIn('product_code', $request->input('product_codes', []));
        }

        $products = $query->orderBy('product_name')->get();
        $path = trim((string) ($finance['kra_plu_register_path'] ?? '/api/register-plu'));
        $service = KraDeviceService::fromSettings($finance);
        $result = $service->registerProducts($products->all(), $path);

        KraDeviceFailure::abortUnlessSuccess($result, 'KRA product registration failed.');

        return response()->json($result);
    }
}
