<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Driver;
use App\Models\Sale;
use Illuminate\Http\Request;

class DriverController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Driver::class;
    }

    public function index(Request $request)
    {
        $query = Driver::query()->with(['defaultVehicle', 'defaultRoute', 'branch']);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = $request->input('q')) {
            $query->where(function ($sub) use ($q) {
                $sub->where('full_name', 'like', "%{$q}%")
                    ->orWhere('driver_code', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderBy('full_name')->paginate($perPage));
    }

    public function show(string $id)
    {
        $driver = Driver::with(['defaultVehicle', 'defaultRoute', 'branch'])->findOrFail($id);

        return response()->json($driver);
    }

    public function store(Request $request)
    {
        $data = $this->validatedDriver($request);
        $driver = Driver::create($data);

        return response()->json($driver->load(['defaultVehicle', 'defaultRoute', 'branch']), 201);
    }

    public function update(Request $request, string $id)
    {
        $driver = Driver::findOrFail($id);
        $driver->update($this->validatedDriver($request, $driver));

        return response()->json($driver->fresh(['defaultVehicle', 'defaultRoute', 'branch']));
    }

    /** GET /drivers/{id}/deliveries — sales linked to this driver */
    public function deliveries(Request $request, int $driver)
    {
        Driver::findOrFail($driver);

        $query = Sale::query()
            ->where('fulfillment_meta->driver_id', $driver)
            ->orderByDesc('id');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($from = $request->input('from_date')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('to_date')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $perPage = min((int) $request->input('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }

    protected function validatedDriver(Request $request, ?Driver $existing = null): array
    {
        return $request->validate([
            'branch_id' => $existing ? 'sometimes|integer|exists:branches,id' : 'required|integer|exists:branches,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'default_vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'default_route_id' => 'nullable|integer|exists:routes,id',
            'driver_code' => ($existing ? 'sometimes|' : '') . 'required|string|max:45',
            'full_name' => ($existing ? 'sometimes|' : '') . 'required|string|max:200',
            'phone' => 'nullable|string|max:45',
            'is_active' => 'nullable|boolean',
        ]);
    }
}
