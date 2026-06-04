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

    public function show(string $id)
    {
        $vehicle = Vehicle::with('branch')->findOrFail($id);

        return response()->json($vehicle);
    }

    /** GET /vehicles/{id}/deliveries — completed sales linked via fulfillment metadata */
    public function deliveries(Request $request, int $vehicle)
    {
        Vehicle::findOrFail($vehicle);

        $query = Sale::query()
            ->where('fulfillment_meta->vehicle_id', $vehicle)
            ->orderByDesc('id');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            $query->where('status', 'completed');
        }

        $perPage = min((int) $request->input('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }
}
