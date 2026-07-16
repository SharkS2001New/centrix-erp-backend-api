<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesBranchScope;
use App\Models\RouteSchedule;
use App\Support\TenantRouteRules;
use Illuminate\Http\Request;

class RouteScheduleController extends BaseResourceController
{
    use HandlesBranchScope;

    protected function modelClass(): string
    {
        return RouteSchedule::class;
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request)
            ->with(['route', 'defaultDriver', 'defaultVehicle']);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, ['route_id', 'day_of_week', 'branch_id', 'is_active'], true)) {
                $query->where($col, $val);
            }
        }

        $perPage = min((int) $request->input('per_page', 100), 200);

        return response()->json($query->orderBy('route_id')->orderBy('day_of_week')->paginate($perPage));
    }

    public function show(Request $request, string $id)
    {
        $schedule = $this->findBranchScopedModel(RouteSchedule::class, $id, $request->user());

        return response()->json($schedule->load(['route', 'defaultDriver', 'defaultVehicle']));
    }

    public function store(Request $request)
    {
        $orgId = (int) ($this->access()->organizationId($request->user(), $request) ?? 0);
        $data = $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id',
            'route_id' => TenantRouteRules::required($orgId ?: null),
            'day_of_week' => 'required|integer|min:0|max:6',
            'default_driver_id' => 'nullable|integer|exists:drivers,id',
            'default_vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'departure_time' => 'nullable|date_format:H:i',
            'is_active' => 'sometimes|boolean',
        ]);

        $user = $request->user();
        if (empty($data['branch_id'])) {
            $data['branch_id'] = $user->branch_id;
        }

        $schedule = RouteSchedule::create($data);

        return response()->json($schedule->load(['route', 'defaultDriver', 'defaultVehicle']), 201);
    }

    public function update(Request $request, string $id)
    {
        $schedule = $this->findBranchScopedModel(RouteSchedule::class, $id, $request->user());
        $orgId = (int) ($this->access()->organizationId($request->user(), $request) ?? 0);
        $data = $request->validate([
            'route_id' => ['sometimes', 'integer', TenantRouteRules::exists($orgId ?: null)],
            'day_of_week' => 'sometimes|integer|min:0|max:6',
            'default_driver_id' => 'sometimes|nullable|integer|exists:drivers,id',
            'default_vehicle_id' => 'sometimes|nullable|integer|exists:vehicles,id',
            'departure_time' => 'sometimes|nullable|date_format:H:i',
            'is_active' => 'sometimes|boolean',
        ]);

        $schedule->update($data);

        return response()->json($schedule->fresh(['route', 'defaultDriver', 'defaultVehicle']));
    }

    public function destroy(Request $request, string $id)
    {
        $schedule = $this->findBranchScopedModel(RouteSchedule::class, $id, $request->user());
        $schedule->delete();

        return response()->json(null, 204);
    }

    /** GET /route-schedules/for-date?date=2024-05-24&branch_id=1 */
    public function forDate(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $user = $request->user();
        $branchId = (int) ($data['branch_id'] ?? $user->branch_id);
        $dayOfWeek = (int) date('w', strtotime($data['date']));

        $query = RouteSchedule::query()
            ->with(['route', 'defaultDriver', 'defaultVehicle'])
            ->where('is_active', true)
            ->where('day_of_week', $dayOfWeek);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        $this->userAccess()->scopeBranchIfLimited($query, $user);

        return response()->json([
            'date' => $data['date'],
            'day_of_week' => $dayOfWeek,
            'schedules' => $query->orderBy('route_id')->get(),
        ]);
    }
}
