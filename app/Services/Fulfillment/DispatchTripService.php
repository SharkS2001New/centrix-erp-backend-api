<?php

namespace App\Services\Fulfillment;

use App\Models\DispatchTrip;
use App\Models\Organization;
use App\Models\RouteSchedule;
use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Sales\RouteOrderScope;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DispatchTripService
{
    public function __construct(
        protected LoadingListBuilder $loadingListBuilder,
        protected TripStockService $tripStock,
        protected TripCapacityValidator $capacityValidator,
        protected FulfillmentNotificationService $notifications,
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
        $routeId = isset($data['route_id']) ? (int) $data['route_id'] : null;

        $driverId = isset($data['driver_id']) ? (int) $data['driver_id'] : null;
        $vehicleId = isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null;

        if (! $driverId && $routeId) {
            $schedule = $this->resolveSchedule($branchId, $routeId, $scheduledDate);
            if ($schedule) {
                $driverId = $schedule->default_driver_id;
                $vehicleId = $vehicleId ?: $schedule->default_vehicle_id;
            }
        }

        return DB::transaction(function () use ($user, $branchId, $scheduledDate, $routeId, $driverId, $vehicleId, $data) {
            $trip = DispatchTrip::create([
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

            $saleIds = array_map('intval', (array) ($data['sale_ids'] ?? []));
            if ($saleIds !== []) {
                $this->assignOrders($trip, $saleIds, $user);
            }

            return $trip->fresh(['route', 'driver', 'vehicle', 'sales', 'loadingList.lines']);
        });
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
        $includeNormalOrders = (bool) ($distributionSettings['include_normal_orders_in_loading_list'] ?? false);

        DB::transaction(function () use ($trip, $sales, $includeNormalOrders) {
            $seq = (int) $trip->sales()->max('dispatch_trip_sales.stop_seq');
            foreach ($sales as $sale) {
                if (! RouteOrderScope::eligibleForLoadingList($sale, $includeNormalOrders)) {
                    throw new InvalidArgumentException(
                        "Order #{$sale->order_num} is not a mobile or route order eligible for loading lists.",
                    );
                }

                if ($trip->route_id && $sale->route_id && (int) $sale->route_id !== (int) $trip->route_id) {
                    throw new InvalidArgumentException("Order #{$sale->order_num} belongs to a different route.");
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

            if (! $trip->route_id) {
                $routeId = $sales->first(fn ($s) => $s->route_id)?->route_id;
                if ($routeId) {
                    $trip->update(['route_id' => $routeId]);
                }
            }
        });

        $this->loadingListBuilder->syncLoadingList($trip->fresh());

        return $trip->fresh(['route', 'driver', 'vehicle', 'sales', 'loadingList.lines']);
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

        return $trip->fresh(['route', 'driver', 'vehicle', 'sales', 'loadingList.lines']);
    }

    public function startTrip(DispatchTrip $trip, User $user): DispatchTrip
    {
        if (! in_array($trip->status, ['draft', 'loading'], true)) {
            throw new InvalidArgumentException('Trip cannot depart from its current status.');
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

        if ($gate->stockDeductTiming() === 'trip_depart') {
            $this->tripStock->deductTripStockIfNeeded($trip, $user);
        }

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
        $expected = (float) ($trip->expected_cash ?? $this->computeExpectedCash($trip, $settings));

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
        if ($loadingList->status !== 'open') {
            throw new InvalidArgumentException('Loading list is already locked.');
        }

        $preparedBy = trim((string) ($data['prepared_by_name'] ?? ''));
        $checkedBy = trim((string) ($data['checked_by_name'] ?? ''));
        if ($preparedBy === '' || $checkedBy === '') {
            throw new InvalidArgumentException('Prepared by and checked by names are required.');
        }

        $settings = $this->erp->gateForUser($user)->distributionSettings();
        $gate = $this->erp->gateForUser($user);
        $trip->load(['vehicle', 'sales']);
        $this->capacityValidator->assertTripCapacity($trip, $settings);

        if ($gate->stockDeductTiming() === 'trip_load') {
            $this->tripStock->deductTripStockIfNeeded($trip, $user);
        }

        $now = now();
        $loadingList->update([
            'status' => 'locked',
            'prepared_by_name' => $preparedBy,
            'checked_by_name' => $checkedBy,
            'locked_at' => $now,
        ]);

        $trip->update([
            'status' => 'loading',
            'prepared_by_name' => $preparedBy,
            'prepared_at' => $now,
            'checked_by_name' => $checkedBy,
            'checked_at' => $now,
        ]);

        return $trip->fresh(['route', 'driver', 'vehicle', 'sales', 'loadingList.lines']);
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
        if (empty($settings['enable_cod_reconciliation'])) {
            return 0.0;
        }

        $trip->loadMissing('sales');
        $total = 0.0;
        foreach ($trip->sales as $sale) {
            $balance = max(0, (float) $sale->order_total - (float) $sale->amount_paid);
            $total += $balance;
        }

        return round($total, 2);
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
}
