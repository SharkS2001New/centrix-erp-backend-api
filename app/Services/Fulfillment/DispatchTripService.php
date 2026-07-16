<?php

namespace App\Services\Fulfillment;

use App\Http\Controllers\Api\V1\Operations\OrderWorkflowController;
use App\Models\DispatchTrip;
use App\Models\Organization;
use App\Models\RouteSchedule;
use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Sales\RouteOrderScope;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DispatchTripService
{
    public function __construct(
        protected LoadingListBuilder $loadingListBuilder,
        protected PickingListBuilder $pickingListBuilder,
        protected TripStockService $tripStock,
        protected TripCapacityValidator $capacityValidator,
        protected FulfillmentNotificationService $notifications,
        protected TripCodService $tripCod,
        protected ErpContext $erp,
    ) {}

    public function generateTripCode(int $branchId, string $date): string
    {
        $prefix = 'TRIP-'.str_replace('-', '', $date);
        $count = DispatchTrip::query()
            ->where('branch_id', $branchId)
            ->where('trip_code', 'like', "{$prefix}-%")
            ->count();

        return sprintf('%s-%03d', $prefix, $count + 1);
    }

    /** @param  array<string, mixed>  $data */
    public function createTrip(User $user, array $data): DispatchTrip
    {
        $branchId = (int) ($data['branch_id'] ?? $user->branch_id);
        if (! $branchId) {
            throw new InvalidArgumentException('Branch is required to create a dispatch trip.');
        }

        $scheduledDate = (string) ($data['scheduled_date'] ?? now()->toDateString());
        $routeIds = $this->normalizeRouteIds($data);
        $routeId = $routeIds[0] ?? (isset($data['route_id']) ? (int) $data['route_id'] : null);

        $driverId = isset($data['driver_id']) ? (int) $data['driver_id'] : null;
        $vehicleId = isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null;
        $requireAssignment = array_key_exists('require_assignment', $data)
            ? (bool) $data['require_assignment']
            : true;

        if ($requireAssignment && (! $driverId || ! $vehicleId)) {
            throw new InvalidArgumentException('Driver and vehicle are required to create a trip chart.');
        }

        if (! $driverId && $routeId) {
            $schedule = $this->resolveSchedule($branchId, $routeId, $scheduledDate);
            if ($schedule) {
                $driverId = $schedule->default_driver_id;
                $vehicleId = $vehicleId ?: $schedule->default_vehicle_id;
            }
        }

        if ($requireAssignment && (! $driverId || ! $vehicleId)) {
            throw new InvalidArgumentException('Driver and vehicle are required to create a trip chart.');
        }

        return DB::transaction(function () use ($user, $branchId, $scheduledDate, $routeId, $routeIds, $driverId, $vehicleId, $data) {
            $trip = DispatchTrip::create([
                'organization_id' => (int) (
                    $user->organization_id
                    ?? \App\Support\OrganizationIdResolver::requireForBranch($branchId)
                ),
                'branch_id' => $branchId,
                'trip_code' => $this->generateTripCode($branchId, $scheduledDate),
                'route_id' => $routeId,
                'driver_id' => $driverId,
                'vehicle_id' => $vehicleId,
                'scheduled_date' => $scheduledDate,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            if ($routeIds !== []) {
                $this->syncTripRoutes($trip, $routeIds);
            }

            $saleIds = array_map('intval', (array) ($data['sale_ids'] ?? []));
            if ($saleIds !== []) {
                $this->assignOrders($trip, $saleIds, $user);
            }

            return $trip->fresh(['route', 'routes', 'driver', 'vehicle', 'sales', 'loadingList.lines', 'pickingList.lines']);
        });
    }

    /**
     * Merge draft trip charts that share the same delivery date into one run.
     *
     * @param  array<int, int>  $sourceTripIds
     * @param  array<string, mixed>  $data
     */
    public function mergeTrips(User $user, array $sourceTripIds, array $data): DispatchTrip
    {
        $sourceTripIds = array_values(array_unique(array_filter(array_map('intval', $sourceTripIds))));
        if (count($sourceTripIds) < 2) {
            throw new InvalidArgumentException('Select at least two trips to merge.');
        }

        $sources = DispatchTrip::query()
            ->with(['sales', 'routes'])
            ->whereIn('id', $sourceTripIds)
            ->get();

        if ($sources->count() !== count($sourceTripIds)) {
            throw new InvalidArgumentException('One or more trips could not be found.');
        }

        $branchId = (int) $sources->first()->branch_id;
        $scheduledDate = (string) $sources->first()->scheduled_date->toDateString();

        foreach ($sources as $source) {
            if ($source->status !== 'draft') {
                throw new InvalidArgumentException('Only draft trips can be merged.');
            }
            if ((int) $source->branch_id !== $branchId) {
                throw new InvalidArgumentException('Trips must belong to the same branch.');
            }
            if ($source->scheduled_date->toDateString() !== $scheduledDate) {
                throw new InvalidArgumentException('Trips must share the same scheduled date.');
            }
        }

        $driverId = (int) ($data['driver_id'] ?? 0);
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        if (! $driverId || ! $vehicleId) {
            throw new InvalidArgumentException('Driver and vehicle are required to merge trip charts.');
        }

        $targetTripId = isset($data['target_trip_id']) ? (int) $data['target_trip_id'] : null;
        $target = $targetTripId
            ? $sources->firstWhere('id', $targetTripId)
            : null;

        if ($targetTripId && ! $target) {
            throw new InvalidArgumentException('Target trip must be one of the selected trips.');
        }

        return DB::transaction(function () use ($user, $sources, $target, $driverId, $vehicleId, $data, $branchId, $scheduledDate) {
            if (! $target) {
                $routeIds = $sources
                    ->flatMap(fn (DispatchTrip $trip) => $trip->routeIdList())
                    ->unique()
                    ->values()
                    ->all();

                $target = DispatchTrip::create([
                    'organization_id' => (int) (
                        $user->organization_id
                        ?? \App\Support\OrganizationIdResolver::requireForBranch($branchId)
                    ),
                    'branch_id' => $branchId,
                    'trip_code' => $this->generateTripCode($branchId, $scheduledDate),
                    'route_id' => $routeIds[0] ?? null,
                    'driver_id' => $driverId,
                    'vehicle_id' => $vehicleId,
                    'scheduled_date' => $scheduledDate,
                    'status' => 'draft',
                    'notes' => $data['notes'] ?? 'Merged trip chart',
                    'created_by' => $user->id,
                ]);
                $this->syncTripRoutes($target, $routeIds);
            } else {
                $target->update([
                    'driver_id' => $driverId,
                    'vehicle_id' => $vehicleId,
                    'notes' => trim((string) ($data['notes'] ?? $target->notes ?? '')) ?: $target->notes,
                ]);
            }

            $seq = (int) $target->sales()->max('dispatch_trip_sales.stop_seq');
            $mergedRouteIds = $target->routeIdList();

            foreach ($sources as $source) {
                if ($source->id === $target->id) {
                    continue;
                }

                $source->loadMissing('sales');
                foreach ($source->sales as $sale) {
                    if ($target->sales()->where('sales.id', $sale->id)->exists()) {
                        continue;
                    }

                    $seq++;
                    $target->sales()->attach($sale->id, ['stop_seq' => $seq]);

                    $meta = array_merge($sale->fulfillment_meta ?? [], [
                        'trip_id' => $target->id,
                        'driver_id' => $driverId,
                        'vehicle_id' => $vehicleId,
                    ]);
                    $sale->update(['fulfillment_meta' => $meta]);

                    if ($sale->route_id) {
                        $mergedRouteIds[] = (int) $sale->route_id;
                    }
                }

                $source->sales()->detach();
                $source->loadingList?->delete();
                $source->pickingList?->delete();
                $source->routes()->detach();
                $source->delete();
            }

            $this->syncTripRoutes($target, $mergedRouteIds);
            $this->syncTripRoutesFromSales($target->fresh());
            $this->syncTripLists($target->fresh());

            return $target->fresh(['route', 'routes', 'driver', 'vehicle', 'sales', 'loadingList.lines', 'pickingList.lines']);
        });
    }

    /** @param  array<string, mixed>  $data
     * @return list<int>
     */
    protected function normalizeRouteIds(array $data): array
    {
        $routeIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ($data['route_ids'] ?? []),
        ))));

        if (isset($data['route_id']) && (int) $data['route_id'] > 0) {
            $routeIds[] = (int) $data['route_id'];
            $routeIds = array_values(array_unique($routeIds));
        }

        return $routeIds;
    }

    /** @param  list<int>  $routeIds */
    protected function syncTripRoutes(DispatchTrip $trip, array $routeIds): void
    {
        $routeIds = array_values(array_unique(array_filter(array_map('intval', $routeIds))));
        $trip->routes()->sync($routeIds);

        if ($routeIds !== []) {
            $trip->update(['route_id' => $routeIds[0]]);
        }
    }

    protected function syncTripRoutesFromSales(DispatchTrip $trip): void
    {
        $trip->loadMissing('sales');
        $routeIds = $trip->sales
            ->pluck('route_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($routeIds !== []) {
            $this->syncTripRoutes($trip, $routeIds);
        }
    }

    /** @return list<int> */
    protected function tripRouteIds(DispatchTrip $trip): array
    {
        $trip->loadMissing('routes');

        return $trip->routeIdList();
    }

    /** @param  array<int, int>  $saleIds */
    public function assignOrders(DispatchTrip $trip, array $saleIds, ?User $user = null): DispatchTrip
    {
        if (in_array($trip->status, ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Cannot assign orders to a completed or cancelled trip.');
        }

        $sales = Sale::query()->whereIn('id', $saleIds)->get();
        if ($sales->isEmpty()) {
            throw new InvalidArgumentException('No valid orders were provided.');
        }

        $distributionSettings = $user
            ? $this->erp->gateForUser($user)->distributionSettings()
            : ($trip->branch?->organization_id
                ? $this->erp->gateForOrganization(Organization::findOrFail($trip->branch->organization_id))->distributionSettings()
                : []);
        $includeNormalOrders = RouteOrderScope::includeNormalOrders($distributionSettings);

        DB::transaction(function () use ($trip, $sales, $includeNormalOrders, $distributionSettings) {
            $tripRouteIds = $this->tripRouteIds($trip);
            $assignStatus = (string) ($distributionSettings['assign_on_status'] ?? 'processed');
            $seq = (int) $trip->sales()->max('dispatch_trip_sales.stop_seq');
            foreach ($sales as $sale) {
                if (! RouteOrderScope::eligibleForLoadingList($sale, $includeNormalOrders)) {
                    throw new InvalidArgumentException(
                        "Order #{$sale->order_num} is not a route order eligible for loading lists.",
                    );
                }

                if ((string) $sale->status !== $assignStatus) {
                    throw new InvalidArgumentException(
                        "Order #{$sale->order_num} must be {$assignStatus} before it can be added to a trip.",
                    );
                }

                if ($tripRouteIds !== [] && $sale->route_id
                    && ! in_array((int) $sale->route_id, $tripRouteIds, true)) {
                    throw new InvalidArgumentException("Order #{$sale->order_num} belongs to a route not on this trip chart.");
                }

                if ($trip->sales()->where('sales.id', $sale->id)->exists()) {
                    continue;
                }

                $seq++;
                $trip->sales()->attach($sale->id, ['stop_seq' => $seq]);

                $meta = array_merge($sale->fulfillment_meta ?? [], ['trip_id' => $trip->id]);
                if ($trip->driver_id) {
                    $meta['driver_id'] = $trip->driver_id;
                }
                if ($trip->vehicle_id) {
                    $meta['vehicle_id'] = $trip->vehicle_id;
                }

                $sale->update(['fulfillment_meta' => $meta]);
            }

            $this->syncTripRoutesFromSales($trip->fresh());
        });

        $fresh = $trip->fresh(['route', 'routes', 'driver', 'vehicle', 'sales', 'loadingList.lines', 'pickingList.lines']);
        $this->refreshExpectedCash($fresh, $user);

        return $fresh->fresh(['route', 'routes', 'driver', 'vehicle', 'sales', 'loadingList.lines', 'pickingList.lines']);
    }

    public function removeOrder(DispatchTrip $trip, int $saleId, ?User $user = null): DispatchTrip
    {
        if ($trip->status !== 'draft') {
            throw new InvalidArgumentException('Orders can only be removed while the trip is still in draft.');
        }

        $trip->load(['loadingList', 'pickingList']);
        if ($trip->loadingList && $trip->loadingList->status !== 'open') {
            throw new InvalidArgumentException('Locking has already started on this trip. Create a new trip assignment instead.');
        }
        if ($trip->pickingList && in_array($trip->pickingList->status, ['completed', 'locked'], true)) {
            throw new InvalidArgumentException('Picking is already completed for this trip. Create a new trip assignment instead.');
        }

        if (! $trip->sales()->where('sales.id', $saleId)->exists()) {
            throw new InvalidArgumentException('That order is not assigned to this trip.');
        }

        DB::transaction(function () use ($trip, $saleId) {
            $trip->sales()->detach($saleId);

            $sale = Sale::query()->find($saleId);
            if (! $sale) {
                return;
            }

            $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
            unset($meta['trip_id'], $meta['driver_id'], $meta['vehicle_id']);
            $sale->update(['fulfillment_meta' => $meta !== [] ? $meta : null]);
        });

        $this->syncTripLists($trip->fresh());
        $fresh = $trip->fresh(['route', 'routes', 'driver', 'vehicle', 'sales', 'loadingList.lines', 'pickingList.lines']);
        $this->refreshExpectedCash($fresh, $user);

        return $fresh->fresh(['route', 'routes', 'driver', 'vehicle', 'sales', 'loadingList.lines', 'pickingList.lines']);
    }

    /** Mark every processed stop on an in-transit trip as delivered. */
    public function confirmAllDelivered(DispatchTrip $trip, User $user): DispatchTrip
    {
        if ($trip->status !== 'in_transit') {
            throw new InvalidArgumentException('Deliveries can only be confirmed while the trip is in transit.');
        }

        $settings = $this->erp->gateForUser($user)->distributionSettings();
        if (! empty($settings['require_pod_on_delivered'])) {
            throw new InvalidArgumentException(
                'Proof of delivery must be captured for each stop before confirming deliveries.',
            );
        }

        $trip->load('sales');
        $workflow = app(\App\Http\Controllers\Api\V1\Operations\OrderWorkflowController::class);

        foreach ($trip->sales as $sale) {
            if (in_array((string) $sale->status, ['delivered', 'completed'], true)) {
                continue;
            }

            if ((string) $sale->status !== 'processed') {
                throw new InvalidArgumentException(
                    "Order #{$sale->order_num} must be processed before confirming delivery.",
                );
            }

            $workflow->transitionSaleForUser(
                $sale,
                'delivered',
                $user,
                [
                    'trip_id' => $trip->id,
                    'delivery_confirmed_via_trip' => true,
                ],
                true,
            );
        }

        $fresh = $trip->fresh(['route', 'routes', 'driver', 'vehicle', 'sales', 'loadingList.lines', 'pickingList.lines']);
        $this->refreshExpectedCash($fresh, $user);

        return $fresh->fresh(['route', 'routes', 'driver', 'vehicle', 'sales', 'loadingList.lines', 'pickingList.lines']);
    }

    /** @param  array<string, mixed>  $data */
    public function updateTrip(DispatchTrip $trip, array $data): DispatchTrip
    {
        if (in_array($trip->status, ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Cannot update a completed or cancelled trip.');
        }

        $updates = array_filter([
            'route_id' => array_key_exists('route_id', $data) ? $data['route_id'] : null,
            'driver_id' => array_key_exists('driver_id', $data) ? $data['driver_id'] : null,
            'vehicle_id' => array_key_exists('vehicle_id', $data) ? $data['vehicle_id'] : null,
            'scheduled_date' => $data['scheduled_date'] ?? null,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : null,
        ], fn ($v, $k) => array_key_exists($k, $data), ARRAY_FILTER_USE_BOTH);

        if ($updates !== []) {
            $trip->update($updates);
        }

        if (array_key_exists('route_ids', $data)) {
            $this->syncTripRoutes($trip, $this->normalizeRouteIds($data));
        }

        return $trip->fresh(['route', 'routes', 'driver', 'vehicle', 'sales', 'loadingList.lines']);
    }

    public function startTrip(DispatchTrip $trip, User $user): DispatchTrip
    {
        if (! in_array($trip->status, ['draft', 'loading'], true)) {
            throw new InvalidArgumentException('Trip cannot depart from its current status.');
        }
        if (! $trip->driver_id || ! $trip->vehicle_id) {
            throw new InvalidArgumentException('Assign a driver and vehicle before starting the trip.');
        }
        if ($trip->sales()->count() === 0) {
            throw new InvalidArgumentException('Assign at least one order before starting the trip.');
        }

        $trip->load(['loadingList.lines', 'vehicle', 'sales']);
        $loadingList = $trip->loadingList;
        if ($loadingList && $loadingList->lines->isNotEmpty() && $loadingList->status === 'open') {
            throw new InvalidArgumentException('Lock the loading list before starting the trip.');
        }

        $settings = $this->erp->gateForUser($user)->distributionSettings();
        $gate = $this->erp->gateForUser($user);
        $this->capacityValidator->assertTripCapacity($trip, $settings);

        $this->tripStock->deductTripStockIfNeeded($trip, $user, 'trip_depart');

        $expectedCash = $this->computeExpectedCash($trip, $settings);
        $trip->update([
            'status' => 'in_transit',
            'departed_at' => now(),
            'expected_cash' => $expectedCash,
        ]);

        $org = Organization::find($user->organization_id);
        if ($org) {
            $this->notifications->notifyTripDispatch($trip->fresh(['sales', 'route']), $org);
        }

        return $trip->fresh(['route', 'driver', 'vehicle', 'sales', 'loadingList.lines']);
    }

    public function completeTrip(DispatchTrip $trip, User $user): DispatchTrip
    {
        if ($trip->status !== 'in_transit') {
            throw new InvalidArgumentException('Only in-transit trips can be completed.');
        }

        $reconciliation = app(TripReconciliationService::class)->build($trip, $user);
        if (! empty($reconciliation['blockers'])) {
            throw new InvalidArgumentException($reconciliation['blockers'][0]);
        }

        $this->finalizeDeliveredOrdersOnTripClose($trip, $user);

        $trip->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $trip->load('loadingList');
        if ($trip->loadingList && $trip->loadingList->status === 'locked') {
            $trip->loadingList->update(['status' => 'loaded']);
        }

        return $trip->fresh(['route', 'driver', 'vehicle', 'sales', 'loadingList.lines']);
    }

    /** @param  array<string, mixed>  $data */
    public function settleTrip(DispatchTrip $trip, User $user, array $data): DispatchTrip
    {
        if (! in_array($trip->status, ['in_transit', 'completed'], true)) {
            throw new InvalidArgumentException('Cash can only be recorded while the trip is in transit or after completion.');
        }

        $settings = $this->erp->gateForUser($user)->distributionSettings();
        if (empty($settings['enable_cod_reconciliation'])) {
            throw new InvalidArgumentException('COD reconciliation is not enabled.');
        }

        $collected = (float) ($data['collected_cash'] ?? 0);
        $reconciliation = app(TripReconciliationService::class)->build($trip, $user);
        $expected = (float) ($reconciliation['cash']['outstanding_from_orders'] ?? 0);

        $trip->update([
            'expected_cash' => $expected,
            'collected_cash' => $collected,
            'cash_variance' => round($collected - $expected, 2),
            'settled_at' => now(),
            'settled_by' => $user->id,
        ]);

        return $trip->fresh(['route', 'driver', 'vehicle', 'sales', 'loadingList.lines']);
    }

    /** @param  array<string, mixed>  $data */
    public function lockLoadingList(DispatchTrip $trip, User $user, array $data): DispatchTrip
    {
        $loadingList = $this->loadingListBuilder->syncLoadingList($trip);
        $pickingList = $this->pickingListBuilder->syncPickingList($trip);
        if ($loadingList->status !== 'open') {
            throw new InvalidArgumentException('Loading list is already locked.');
        }

        $preparedBy = trim((string) ($data['prepared_by_name'] ?? ''));
        $checkedBy = trim((string) ($data['checked_by_name'] ?? ''));
        if ($preparedBy === '' || $checkedBy === '') {
            throw new InvalidArgumentException('Prepared by and checked by names are required.');
        }

        $settings = $this->erp->gateForUser($user)->distributionSettings();
        if (! empty($settings['require_picking_before_lock'])) {
            $pickingList->refresh();
            if (! in_array($pickingList->status, ['completed', 'locked'], true)) {
                throw new InvalidArgumentException(
                    'Complete warehouse picking before locking the loading list.',
                );
            }
        }

        $gate = $this->erp->gateForUser($user);
        $trip->load(['vehicle', 'sales']);
        $this->capacityValidator->assertTripCapacity($trip, $settings);

        $this->tripStock->deductTripStockIfNeeded($trip, $user, 'trip_load');

        $now = now();
        $loadingList->update([
            'status' => 'locked',
            'prepared_by_name' => $preparedBy,
            'checked_by_name' => $checkedBy,
            'locked_at' => $now,
        ]);

        $this->pickingListBuilder->lockPickingList($pickingList);

        $trip->update([
            'status' => 'loading',
            'prepared_by_name' => $preparedBy,
            'prepared_at' => $now,
            'checked_by_name' => $checkedBy,
            'checked_at' => $now,
        ]);

        return $trip->fresh(['route', 'driver', 'vehicle', 'sales', 'loadingList.lines', 'pickingList.lines']);
    }

    protected function syncTripLists(DispatchTrip $trip): void
    {
        $this->loadingListBuilder->syncLoadingList($trip);
        $this->pickingListBuilder->syncPickingList($trip);
    }

    /** @param  array<int, array{sale_id: int, stop_seq: int}>  $stops */
    public function reorderStops(DispatchTrip $trip, array $stops): DispatchTrip
    {
        if (in_array($trip->status, ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Cannot reorder stops on a completed or cancelled trip.');
        }

        DB::transaction(function () use ($trip, $stops) {
            foreach ($stops as $stop) {
                $saleId = (int) ($stop['sale_id'] ?? 0);
                $seq = (int) ($stop['stop_seq'] ?? 0);
                if ($saleId <= 0 || $seq <= 0) {
                    continue;
                }

                $trip->sales()->updateExistingPivot($saleId, ['stop_seq' => $seq]);
            }
        });

        return $trip->fresh(['route', 'driver', 'vehicle', 'sales', 'loadingList.lines']);
    }

    /** @param  array<string, mixed>  $settings */
    public function computeExpectedCash(DispatchTrip $trip, array $settings): float
    {
        $trip->loadMissing('sales');

        if (in_array($trip->status, ['in_transit', 'completed'], true)) {
            return $this->tripCod->outstandingFromTrip($trip, $settings);
        }

        return $this->tripCod->expectedAtDepart($trip->sales, $settings);
    }

  /** @return array<string, mixed> */
    public function cashSummary(DispatchTrip $trip, ?User $user): array
    {
        if (! $user) {
            return [
                'enabled' => false,
                'expected_from_orders' => null,
                'outstanding_from_orders' => null,
            ];
        }

        $settings = $this->erp->gateForUser($user)->distributionSettings();
        $enabled = ! empty($settings['enable_cod_reconciliation']);
        if (! $enabled) {
            return [
                'enabled' => false,
                'expected_from_orders' => 0.0,
                'outstanding_from_orders' => 0.0,
            ];
        }

        $trip->loadMissing('sales');
        $outstanding = $this->tripCod->outstandingFromTrip($trip, $settings);
        $atDepart = $this->tripCod->expectedAtDepart($trip->sales, $settings);

        return [
            'enabled' => true,
            'expected_from_orders' => in_array($trip->status, ['in_transit', 'completed'], true)
                ? $outstanding
                : $atDepart,
            'outstanding_from_orders' => $outstanding,
            'expected_at_depart' => $atDepart,
        ];
    }

    public function refreshExpectedCash(DispatchTrip $trip, ?User $user = null): void
    {
        if (! in_array($trip->status, ['in_transit', 'completed'], true)) {
            return;
        }

        $settings = $user
            ? $this->erp->gateForUser($user)->distributionSettings()
            : ($trip->branch?->organization_id
                ? $this->erp->gateForOrganization(Organization::findOrFail($trip->branch->organization_id))->distributionSettings()
                : []);

        if (empty($settings['enable_cod_reconciliation'])) {
            return;
        }

        $trip->update([
            'expected_cash' => $this->computeExpectedCash($trip, $settings),
        ]);
    }

    public function resolveSchedule(int $branchId, int $routeId, string $date): ?RouteSchedule
    {
        $dayOfWeek = (int) date('w', strtotime($date));

        return RouteSchedule::query()
            ->where('branch_id', $branchId)
            ->where('route_id', $routeId)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->with(['defaultDriver', 'defaultVehicle'])
            ->first();
    }

    protected function finalizeDeliveredOrdersOnTripClose(DispatchTrip $trip, User $user): void
    {
        $trip->load('sales');
        $gate = $this->erp->gateForUser($user);
        $workflow = OrderWorkflowService::forGate($gate);
        $workflowController = app(OrderWorkflowController::class);

        foreach ($trip->sales as $sale) {
            if ((string) $sale->status !== 'delivered') {
                continue;
            }

            if ($sale->is_credit_sale) {
                $channel = $workflow->normalizeSalesChannel((string) ($sale->channel ?: 'backend'));
                $sale->update([
                    'status' => $workflow->pickEnabledStatus('completed', $workflow->forChannel($channel)),
                    'completed_at' => $sale->completed_at ?? now(),
                ]);

                continue;
            }

            $workflowController->transitionSaleForUser(
                $sale,
                'completed',
                $user,
                ['trip_close_finalized' => true],
            );
        }
    }
}
