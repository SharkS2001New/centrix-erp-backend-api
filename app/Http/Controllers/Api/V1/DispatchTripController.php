<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesBranchScope;
use App\Models\DispatchTrip;
use App\Models\Employee;
use App\Services\Fulfillment\DispatchTripService;
use App\Services\Fulfillment\TripFinancialSummaryService;
use App\Services\Fulfillment\TripStockService;
use App\Services\Notifications\AdminNotificationService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class DispatchTripController extends BaseResourceController
{
    use HandlesBranchScope;

    public function __construct(
        protected DispatchTripService $trips,
        protected TripFinancialSummaryService $financials,
    ) {}

    protected function modelClass(): string
    {
        return DispatchTrip::class;
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request)
            ->with(['route', 'routes', 'driver', 'vehicle', 'crewMembers'])
            ->withCount('sales');

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, ['status', 'route_id', 'driver_id', 'scheduled_date', 'branch_id'], true)) {
                $query->where($col, $val);
            }
        }

        if ($from = $request->input('from_date')) {
            $query->whereDate('scheduled_date', '>=', $from);
        }
        if ($to = $request->input('to_date')) {
            $query->whereDate('scheduled_date', '<=', $to);
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        $paginator = $query->orderByDesc('scheduled_date')->orderByDesc('id')->paginate($perPage);
        $summaries = $this->financials->summarizeForTripIds(
            $paginator->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
        $paginator->getCollection()->transform(
            fn (DispatchTrip $trip) => $this->presentTrip($trip, $summaries[(int) $trip->id] ?? null),
        );

        return response()->json($paginator);
    }

    public function show(Request $request, string $id)
    {
        $trip = $this->findBranchScopedModel(DispatchTrip::class, $id, $request->user());

        return response()->json(
            $this->presentTrip($trip->load(['route', 'routes', 'driver', 'vehicle', 'crewMembers', 'sales', 'loadingList.lines'])),
        );
    }

    /** @return array<string, mixed> */
    protected function presentTrip(DispatchTrip $trip, ?array $financialSummary = null): array
    {
        $payload = $trip->toArray();
        $payload['route_ids'] = $trip->routeIdList();
        $payload['route_names'] = $trip->relationLoaded('routes') && $trip->routes->isNotEmpty()
            ? $trip->routes->pluck('route_name')->values()->all()
            : ($trip->route ? [$trip->route->route_name] : []);
        $payload['is_multi_route'] = count($payload['route_ids']) > 1;
        $payload['crew_employee_ids'] = $trip->relationLoaded('crewMembers')
            ? $trip->crewMembers->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
            : [];
        $payload['turn_boys'] = $trip->relationLoaded('crewMembers')
            ? $trip->crewMembers->values()->map(fn (Employee $employee) => [
                'id' => (int) $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name' => $employee->full_name,
                'phone' => $employee->phone,
                'role' => $employee->pivot?->role ?? 'turn_boy',
            ])->all()
            : [];
        $payload['financial_summary'] = $financialSummary
            ?? ($trip->relationLoaded('sales')
                ? $this->financials->summarizeForTrip($trip)
                : $this->financials->emptySummary());

        return $payload;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id',
            'route_id' => 'nullable|integer|exists:routes,id',
            'route_ids' => 'sometimes|array|min:1',
            'route_ids.*' => 'integer|exists:routes,id',
            'driver_id' => 'required|integer|exists:drivers,id',
            'vehicle_id' => 'required|integer|exists:vehicles,id',
            'scheduled_date' => 'required|date',
            'notes' => 'nullable|string|max:2000',
            'sale_ids' => 'sometimes|array',
            'sale_ids.*' => 'integer|exists:sales,id',
            'crew_employee_ids' => 'sometimes|array',
            'crew_employee_ids.*' => 'integer|exists:employees,id',
        ]);

        try {
            $trip = $this->trips->createTrip($request->user(), $data);
            $this->syncCrew($request, $trip, $data['crew_employee_ids'] ?? []);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->presentTrip($trip->fresh(['route', 'routes', 'driver', 'vehicle', 'crewMembers'])), 201);
    }

    public function update(Request $request, string $id)
    {
        $trip = $this->findBranchScopedModel(DispatchTrip::class, $id, $request->user());
        $data = $request->validate([
            'route_id' => 'sometimes|nullable|integer|exists:routes,id',
            'route_ids' => 'sometimes|array|min:1',
            'route_ids.*' => 'integer|exists:routes,id',
            'driver_id' => 'sometimes|nullable|integer|exists:drivers,id',
            'vehicle_id' => 'sometimes|nullable|integer|exists:vehicles,id',
            'scheduled_date' => 'sometimes|date',
            'notes' => 'sometimes|nullable|string|max:2000',
            'crew_employee_ids' => 'sometimes|array',
            'crew_employee_ids.*' => 'integer|exists:employees,id',
        ]);

        try {
            $trip = $this->trips->updateTrip($trip, $data);
            if (array_key_exists('crew_employee_ids', $data)) {
                $this->syncCrew($request, $trip, $data['crew_employee_ids'] ?? []);
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->presentTrip($trip->fresh(['route', 'routes', 'driver', 'vehicle', 'crewMembers'])));
    }

    public function destroy(Request $request, string $id)
    {
        $trip = $this->findBranchScopedModel(DispatchTrip::class, $id, $request->user());
        if (! in_array($trip->status, ['draft', 'cancelled'], true)) {
            return response()->json(['message' => 'Only draft trips can be deleted. Cancel the trip first.'], 422);
        }
        $trip->delete();

        return response()->json(null, 204);
    }

    /** @param list<int|string> $employeeIds */
    protected function syncCrew(Request $request, DispatchTrip $trip, array $employeeIds): void
    {
        $ids = collect($employeeIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $trip->crewMembers()->sync([]);
            return;
        }

        $orgId = $this->access()->organizationId($request->user(), $request);
        $employees = Employee::query()
            ->where('organization_id', $orgId)
            ->whereIn('id', $ids)
            ->get(['id']);

        if ($employees->count() !== $ids->count()) {
            throw new InvalidArgumentException('One or more selected turn boys do not belong to this organization.');
        }

        $driverEmployeeId = (int) optional($trip->driver()->first())->employee_id;
        if ($driverEmployeeId > 0 && $ids->contains($driverEmployeeId)) {
            throw new InvalidArgumentException('The driver cannot also be selected as a turn boy on the same trip.');
        }

        $sync = $ids->mapWithKeys(fn ($id) => [$id => ['role' => 'turn_boy']])->all();
        $trip->crewMembers()->sync($sync);
    }

    public function assignOrders(Request $request, int $trip)
    {
        $model = $this->findBranchScopedModel(DispatchTrip::class, $trip, $request->user());
        $data = $request->validate([
            'sale_ids' => 'required|array|min:1',
            'sale_ids.*' => 'integer|exists:sales,id',
        ]);

        try {
            $updated = $this->trips->assignOrders($model, $data['sale_ids']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->presentTrip($updated));
    }

    public function merge(Request $request)
    {
        $data = $request->validate([
            'trip_ids' => 'required|array|min:2',
            'trip_ids.*' => 'integer|exists:dispatch_trips,id',
            'target_trip_id' => 'nullable|integer|exists:dispatch_trips,id',
            'driver_id' => 'required|integer|exists:drivers,id',
            'vehicle_id' => 'required|integer|exists:vehicles,id',
            'notes' => 'nullable|string|max:2000',
            'crew_employee_ids' => 'sometimes|array',
            'crew_employee_ids.*' => 'integer|exists:employees,id',
        ]);

        try {
            $trip = $this->trips->mergeTrips($request->user(), $data['trip_ids'], $data);
            $this->syncCrew($request, $trip, $data['crew_employee_ids'] ?? []);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->presentTrip($trip->fresh(['route', 'routes', 'driver', 'vehicle', 'crewMembers'])));
    }

    public function loadingList(Request $request, int $trip)
    {
        $model = $this->findBranchScopedModel(DispatchTrip::class, $trip, $request->user());
        $builder = app(\App\Services\Fulfillment\LoadingListBuilder::class);
        $loadingList = $builder->syncLoadingList($model);
        $loadingList->load(['route', 'trip.route', 'trip.driver', 'trip.vehicle']);
        $payload = $loadingList->toArray();
        $payload['lines'] = $builder->linesForTrip($model);

        try {
            $financialSummary = $this->financials->summarizeForTrip($model);
        } catch (\Throwable $e) {
            report($e);
            $financialSummary = $this->financials->emptySummary();
        }

        return response()->json([
            'loading_list' => $payload,
            'financial_summary' => $financialSummary,
        ]);
    }

    public function pickingList(Request $request, int $trip)
    {
        $model = $this->findBranchScopedModel(DispatchTrip::class, $trip, $request->user());
        $builder = app(\App\Services\Fulfillment\PickingListBuilder::class);
        $pickingList = $builder->syncPickingList($model);
        $pickingList->load(['route', 'trip.route', 'trip.driver', 'trip.vehicle']);

        return response()->json([
            'picking_list' => $pickingList,
        ]);
    }

    public function updatePickingListLines(Request $request, int $trip)
    {
        $model = $this->findBranchScopedModel(DispatchTrip::class, $trip, $request->user());
        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.id' => 'nullable|integer',
            'lines.*.product_code' => 'nullable|string|max:200',
            'lines.*.picked_qty' => 'required|numeric|min:0',
            'lines.*.shortage_reason' => 'nullable|string|max:255',
        ]);

        $builder = app(\App\Services\Fulfillment\PickingListBuilder::class);
        $pickingList = $builder->syncPickingList($model);
        $updated = $builder->updatePickedQuantities($pickingList, $data['lines']);

        return response()->json([
            'picking_list' => $updated,
        ]);
    }

    public function completePickingList(Request $request, int $trip)
    {
        $model = $this->findBranchScopedModel(DispatchTrip::class, $trip, $request->user());
        $data = $request->validate([
            'picker_name' => 'nullable|string|max:200',
        ]);

        $builder = app(\App\Services\Fulfillment\PickingListBuilder::class);
        $pickingList = $builder->syncPickingList($model);
        $updated = $builder->completePickingList(
            $pickingList,
            $data['picker_name'] ?? null,
        );
        app(TripStockService::class)->deductDeferredTripStockOnPickingComplete($model, $request->user());

        return response()->json([
            'picking_list' => $updated,
        ]);
    }

    public function lockLoadingList(Request $request, int $trip)
    {
        $model = $this->findBranchScopedModel(DispatchTrip::class, $trip, $request->user());
        $data = $request->validate([
            'prepared_by_name' => 'required|string|max:200',
            'checked_by_name' => 'required|string|max:200',
        ]);

        try {
            $updated = $this->trips->lockLoadingList($model, $request->user(), $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($updated);
    }

    public function start(Request $request, int $trip)
    {
        $model = $this->findBranchScopedModel(DispatchTrip::class, $trip, $request->user());

        try {
            $updated = $this->trips->startTrip($model, $request->user());
            $this->notifyTripManagers($request, $updated, 'Trip chart started', 'started');
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($updated);
    }

    public function confirmDeliveries(Request $request, int $trip)
    {
        $model = $this->findBranchScopedModel(DispatchTrip::class, $trip, $request->user());

        try {
            $updated = $this->trips->confirmAllDelivered($model, $request->user());
            $this->notifyTripManagers($request, $updated, 'Trip deliveries confirmed', 'confirmed deliveries for');
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->presentTrip($updated));
    }

    public function complete(Request $request, int $trip)
    {
        $model = $this->findBranchScopedModel(DispatchTrip::class, $trip, $request->user());

        try {
            $updated = $this->trips->completeTrip($model, $request->user());
            $this->notifyTripManagers($request, $updated, 'Trip chart closed', 'closed');
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($updated);
    }

    public function settle(Request $request, int $trip)
    {
        $model = $this->findBranchScopedModel(DispatchTrip::class, $trip, $request->user());
        $data = $request->validate([
            'collected_cash' => 'required|numeric|min:0',
        ]);

        try {
            $updated = $this->trips->settleTrip($model, $request->user(), $data);
            $this->notifyTripManagers($request, $updated, 'Trip cash settlement recorded', 'settled cash for');
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($updated);
    }

    public function cancel(Request $request, int $trip)
    {
        $model = $this->findBranchScopedModel(DispatchTrip::class, $trip, $request->user());
        if ($model->status === 'completed') {
            return response()->json(['message' => 'Completed trips cannot be cancelled.'], 422);
        }
        $model->update(['status' => 'cancelled']);
        $this->notifyTripManagers($request, $model->fresh(), 'Trip chart cancelled', 'cancelled');

        return response()->json($model->fresh(['route', 'driver', 'vehicle', 'sales', 'loadingList.lines']));
    }

    public function reorderStops(Request $request, int $trip)
    {
        $model = $this->findBranchScopedModel(DispatchTrip::class, $trip, $request->user());
        $data = $request->validate([
            'stops' => 'required|array|min:1',
            'stops.*.sale_id' => 'required|integer|exists:sales,id',
            'stops.*.stop_seq' => 'required|integer|min:1',
        ]);

        try {
            $updated = $this->trips->reorderStops($model, $data['stops']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($updated);
    }

    public function reconciliation(Request $request, int $trip)
    {
        $model = $this->findBranchScopedModel(DispatchTrip::class, $trip, $request->user());

        return response()->json(
            app(\App\Services\Fulfillment\TripReconciliationService::class)->build($model, $request->user()),
        );
    }

    protected function notifyTripManagers(Request $request, DispatchTrip $trip, string $title, string $verb): void
    {
        $user = $request->user();
        if (! $user) {
            return;
        }

        app(AdminNotificationService::class)->notifyPermission($user, 'fulfillment.manage', [
            'type' => 'info',
            'severity' => $trip->status === 'cancelled' ? 'warning' : 'default',
            'title' => $title,
            'message' => ($user->full_name ?: $user->username)." {$verb} trip {$trip->trip_code}.",
            'action_url' => "/dispatch-trips/{$trip->id}",
        ]);
    }
}
