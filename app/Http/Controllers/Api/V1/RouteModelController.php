<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\TemporaryCart;
use App\Services\Sales\ReceiptPaymentDetailsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RouteModelController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return RouteModel::class;
    }

    public function store(Request $request)
    {
        $data = $this->validateRoutePayload($request);
        $model = RouteModel::create($data);

        if ($request->user() && $this->auditable()) {
            $this->auditLogger()->logModel($request->user(), 'create', $model, request: $request);
        }

        return response()->json($model, 201);
    }

    public function update(Request $request, string $id)
    {
        $model = $this->findScopedModel($request, $id);
        $data = $this->validateRoutePayload($request, partial: true);
        $oldValues = $model->getAttributes();
        $model->update($data);
        $model->refresh();

        if ($request->user() && $this->auditable()) {
            $this->auditLogger()->logModel(
                $request->user(),
                'update',
                $model,
                $oldValues,
                $model->getAttributes(),
                $request,
            );
        }

        return response()->json($model);
    }

    /** @return array<string, mixed> */
    protected function validateRoutePayload(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes' : 'required';

        $rules = array_merge([
            'route_name' => "{$prefix}|string|max:255",
            'direction' => 'nullable|string|max:45',
            'route_markup_price' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ], ReceiptPaymentDetailsResolver::validationRules());

        $data = $request->validate($rules);

        if (array_key_exists('receipt_payment_details', $data)) {
            $data['receipt_payment_details'] = ReceiptPaymentDetailsResolver::normalize(
                is_array($data['receipt_payment_details']) ? $data['receipt_payment_details'] : null,
            );
        }

        return $data;
    }

    public function destroy(Request $request, string $id)
    {
        $route = $this->findScopedModel($request, $id);

        DB::transaction(function () use ($route) {
            Customer::where('route_id', $route->id)->update(['route_id' => null]);
            TemporaryCart::where('route_id', $route->id)->update(['route_id' => null]);
            Sale::where('route_id', $route->id)->update(['route_id' => null]);
            Driver::where('default_route_id', $route->id)->update(['default_route_id' => null]);
            $route->delete();
        });

        return response()->json(null, 204);
    }
}
