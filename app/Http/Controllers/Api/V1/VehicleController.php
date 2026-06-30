<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Sale;
use App\Models\Vehicle;
use Illuminate\Http\Request;

class VehicleController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Vehicle::class;
    }

    protected function baseQuery(Request $request)
    {
        return parent::baseQuery($request)->with('branch');
    }

    public function show(Request $request, string $id)
    {
        return response()->json($this->findScopedModel($request, $id)->load('branch'));
    }

    /** GET /vehicles/{id}/deliveries — completed sales linked via fulfillment metadata */
    public function deliveries(Request $request, int $vehicle)
    {
        $this->findScopedModel($request, (string) $vehicle);

        $query = Sale::query()
            ->where('organization_id', $this->access()->organizationId($request->user(), $request))
            ->where('fulfillment_meta->vehicle_id', $vehicle)
            ->orderByDesc('id');

        $this->access()->scopeBranchIfLimited($query, $request->user());

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            $query->where('status', 'completed');
        }

        $perPage = min((int) $request->input('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }
}
