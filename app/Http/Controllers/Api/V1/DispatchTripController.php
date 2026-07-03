<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesBranchScope;
use App\Models\DispatchTrip;
use App\Services\Fulfillment\DispatchTripService;
use App\Services\Fulfillment\TripFinancialSummaryService;
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
            ->with(['route', 'routes', 'driver', 'vehicle'])
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
            $this->presentTrip($trip->load(['route', 'routes', 'driver', 'vehicle', 'sales', 'loadingList.lines'])),
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
        ]);

        try {
            $trip = $this->trips->createTrip($request->user(), $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->presentTrip($trip), 201);
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
        ]);

        try {
            $trip = $this->trips->updateTrip($trip, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->presentTrip($trip));
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
        ]);

        try {
            $trip = $this->trips->mergeTrips($request->user(), $data['trip_ids'], $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->presentTrip($trip));
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
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($updated);
    }

    public function complete(Request $request, int $trip)
    {
        $model = $this->findBranchScopedModel(DispatchTrip::class, $trip, $request->user());

        try {
            $updated = $this->trips->completeTrip($model, $request->user());
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
}
