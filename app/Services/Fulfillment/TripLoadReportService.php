<?php

namespace App\Services\Fulfillment;

use App\Models\DispatchTrip;
use App\Services\Auth\UserAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class TripLoadReportService
{
    public function __construct(
        protected TripFinancialSummaryService $financialSummary,
        protected LoadingListBuilder $loadingListBuilder,
        protected UserAccessService $access,
    ) {}

    /**
     * @param  'vehicle'|'driver'  $groupBy
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, per_page: int, total: int, summary: array<string, mixed>}
     */
    public function paginate(Request $request, string $groupBy): array
    {
        $user = $request->user();
        abort_unless($user, 403);

        $orgId = $this->access->organizationId($user, $request);
        abort_unless($orgId, 403);

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $page = max((int) $request->input('page', 1), 1);

        $query = DispatchTrip::query()
            ->with([
                'driver:id,full_name',
                'vehicle:id,vehicle_name,plate_number,max_weight_kg',
                'route:id,route_name',
                'routes:id,route_name',
                'branch:id,organization_id',
            ])
            ->where('organization_id', $orgId)
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id');

        if (! $this->access->isOrgWide($user)) {
            $branchId = $this->access->branchId($user);
            if ($branchId !== null) {
                $query->where('branch_id', $branchId);
            }
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->input('branch_id'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('scheduled_date', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('scheduled_date', '<=', $request->input('to_date'));
        }

        if ($groupBy === 'vehicle') {
            $query->whereNotNull('vehicle_id');
            if ($request->filled('vehicle_id')) {
                $query->where('vehicle_id', (int) $request->input('vehicle_id'));
            }
        } else {
            $query->whereNotNull('driver_id');
            if ($request->filled('driver_id')) {
                $query->where('driver_id', (int) $request->input('driver_id'));
            }
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $like = '%'.$search.'%';
            $query->where(function ($inner) use ($like, $groupBy) {
                $inner->where('trip_code', 'like', $like)
                    ->orWhere('status', 'like', $like)
                    ->orWhereHas('route', fn ($q) => $q->where('route_name', 'like', $like))
                    ->orWhereHas('routes', fn ($q) => $q->where('route_name', 'like', $like));
                if ($groupBy === 'vehicle') {
                    $inner->orWhereHas('vehicle', function ($q) use ($like) {
                        $q->where('plate_number', 'like', $like)
                            ->orWhere('vehicle_name', 'like', $like);
                    });
                } else {
                    $inner->orWhereHas('driver', fn ($q) => $q->where('full_name', 'like', $like));
                }
            });
        }

        /** @var LengthAwarePaginator<int, DispatchTrip> $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $trips = collect($paginator->items());
        $tripIds = $trips->pluck('id')->map(fn ($id) => (int) $id)->all();

        $summaries = $this->financialSummary->summarizeForTripIds($tripIds, true);
        $weights = $this->loadingListBuilder->computeTripWeightsKgByTripIds($tripIds);

        $rows = [];
        foreach ($trips as $trip) {
            $tripId = (int) $trip->id;
            $financial = $summaries[$tripId] ?? $this->financialSummary->emptySummary();
            $weightKg = (float) ($weights[$tripId] ?? 0);
            $maxWeight = $trip->vehicle?->max_weight_kg !== null
                ? (float) $trip->vehicle->max_weight_kg
                : null;
            $utilization = ($maxWeight !== null && $maxWeight > 0)
                ? round(($weightKg / $maxWeight) * 100, 1)
                : null;

            $routeNames = $trip->relationLoaded('routes') && $trip->routes->isNotEmpty()
                ? $trip->routes->pluck('route_name')->filter()->values()->all()
                : array_filter([(string) ($trip->route?->route_name ?? '')]);

            $rows[] = [
                'trip_id' => $tripId,
                'trip_code' => (string) $trip->trip_code,
                'scheduled_date' => optional($trip->scheduled_date)?->toDateString(),
                'status' => (string) $trip->status,
                'route_name' => implode(', ', $routeNames),
                'vehicle_id' => $trip->vehicle_id ? (int) $trip->vehicle_id : null,
                'vehicle_plate' => $trip->vehicle?->plate_number,
                'vehicle_name' => $trip->vehicle?->vehicle_name,
                'driver_id' => $trip->driver_id ? (int) $trip->driver_id : null,
                'driver_name' => $trip->driver?->full_name,
                'order_count' => (int) ($financial['order_count'] ?? 0),
                'total_weight_kg' => round($weightKg, 3),
                'max_weight_kg' => $maxWeight,
                'weight_utilization_percent' => $utilization,
                'net_revenue' => $financial['net_revenue'] ?? 0,
                'total_cogs' => $financial['total_cogs'] ?? 0,
                'total_profit' => $financial['total_profit'] ?? 0,
                'total_expenses' => $financial['total_expenses'] ?? 0,
                'net_profit' => $financial['net_profit'] ?? 0,
                'net_profit_margin_percent' => $financial['net_profit_margin_percent'] ?? null,
                'cogs_included' => $financial['cogs_included'] ?? true,
            ];
        }

        return [
            'data' => $rows,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'summary' => $this->summarizeRows($rows),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function summarizeRows(array $rows): array
    {
        $orderCount = 0;
        $weight = 0.0;
        $cogs = 0.0;
        $expenses = 0.0;
        $netProfit = 0.0;
        $netRevenue = 0.0;

        foreach ($rows as $row) {
            $orderCount += (int) ($row['order_count'] ?? 0);
            $weight += (float) ($row['total_weight_kg'] ?? 0);
            $cogs += (float) ($row['total_cogs'] ?? 0);
            $expenses += (float) ($row['total_expenses'] ?? 0);
            $netProfit += (float) ($row['net_profit'] ?? 0);
            $netRevenue += (float) ($row['net_revenue'] ?? 0);
        }

        return [
            'order_count' => $orderCount,
            'total_weight_kg' => round($weight, 3),
            'total_cogs' => round($cogs, 2),
            'total_expenses' => round($expenses, 2),
            'net_profit' => round($netProfit, 2),
            'net_revenue' => round($netRevenue, 2),
            'trip_count' => count($rows),
        ];
    }
}
