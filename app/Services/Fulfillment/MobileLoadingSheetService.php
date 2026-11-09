<?php

namespace App\Services\Fulfillment;

use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Fulfillment\RouteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MobileLoadingSheetService
{
    private const EXCLUDED_STATUSES = ['draft', 'held', 'cancelled', 'expired'];

    public function __construct(
        protected LoadingListBuilder $loadingListBuilder,
        protected UserAccessService $access,
        protected RouteAccessService $routes,
        protected LoadTonnagePresenter $tonnage,
    ) {}

    public function assertAvailable(bool $distributionOpsEnabled, bool $mobileSalesEnabled): void
    {
        if ($distributionOpsEnabled) {
            throw new InvalidArgumentException(
                'Loading sheets for mobile orders are managed under Distribution dispatch trips.',
            );
        }

        if (! $mobileSalesEnabled) {
            throw new InvalidArgumentException('Mobile field sales are not enabled for this organization.');
        }
    }

    /** @return Builder<Sale> */
    protected function baseMobileSalesQuery(User $user): Builder
    {
        $query = Sale::query()
            ->where('channel', 'mobile')
            ->whereNotNull('route_id')
            ->whereNotIn('status', self::EXCLUDED_STATUSES);

        if ($user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        }

        $this->access->scopeOrganization($query, $user, 'sales.organization_id');

        return $query;
    }

    protected function loadingDateExpression(): string
    {
        if (\App\Support\SchemaProbe::hasColumn('sales', 'loading_date')) {
            return 'sales.loading_date';
        }

        return 'COALESCE(DATE(sales.delivery_date), DATE(sales.created_at))';
    }

    /**
     * Sargable day filter equivalent to COALESCE(DATE(delivery_date), DATE(created_at)).
     * Prefer the generated loading_date column when present.
     *
     * @param  Builder<Sale>  $query
     */
    protected function applyLoadingDateEquals(Builder $query, string $listDate): void
    {
        if (\App\Support\SchemaProbe::hasColumn('sales', 'loading_date')) {
            $query->where('sales.loading_date', $listDate);

            return;
        }

        $start = $listDate.' 00:00:00';
        $end = $listDate.' 23:59:59';
        $query->where(function ($outer) use ($start, $end) {
            $outer->where(function ($withDelivery) use ($start, $end) {
                $withDelivery->whereNotNull('sales.delivery_date')
                    ->whereBetween('sales.delivery_date', [$start, $end]);
            })->orWhere(function ($fallback) use ($start, $end) {
                $fallback->whereNull('sales.delivery_date')
                    ->whereBetween('sales.created_at', [$start, $end]);
            });
        });
    }

    /**
     * @param  Builder<Sale>  $query
     */
    protected function applyLoadingDateRange(Builder $query, ?string $from, ?string $to): void
    {
        if ($from === null && $to === null) {
            return;
        }

        if (\App\Support\SchemaProbe::hasColumn('sales', 'loading_date')) {
            if ($from !== null) {
                $query->where('sales.loading_date', '>=', $from);
            }
            if ($to !== null) {
                $query->where('sales.loading_date', '<=', $to);
            }

            return;
        }

        $fromStart = $from ? $from.' 00:00:00' : null;
        $toEnd = $to ? $to.' 23:59:59' : null;

        $query->where(function ($outer) use ($fromStart, $toEnd) {
            $outer->where(function ($withDelivery) use ($fromStart, $toEnd) {
                $withDelivery->whereNotNull('sales.delivery_date');
                if ($fromStart !== null) {
                    $withDelivery->where('sales.delivery_date', '>=', $fromStart);
                }
                if ($toEnd !== null) {
                    $withDelivery->where('sales.delivery_date', '<=', $toEnd);
                }
            })->orWhere(function ($fallback) use ($fromStart, $toEnd) {
                $fallback->whereNull('sales.delivery_date');
                if ($fromStart !== null) {
                    $fallback->where('sales.created_at', '>=', $fromStart);
                }
                if ($toEnd !== null) {
                    $fallback->where('sales.created_at', '<=', $toEnd);
                }
            });
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function listSheets(User $user, array $filters = []): array
    {
        $dateExpr = $this->loadingDateExpression();
        $query = $this->baseMobileSalesQuery($user)
            ->select([
                'route_id',
                DB::raw("{$dateExpr} as list_date"),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(order_total) as order_total'),
            ])
            ->groupBy('route_id', DB::raw($dateExpr));

        if ($routeId = (int) ($filters['route_id'] ?? 0)) {
            $query->where('route_id', $routeId);
        }
        $this->applyLoadingDateRange(
            $query,
            isset($filters['from_date']) ? (string) $filters['from_date'] : null,
            isset($filters['to_date']) ? (string) $filters['to_date'] : null,
        );

        $rows = $query
            ->orderByDesc(DB::raw($dateExpr))
            ->orderBy('route_id')
            ->limit(200)
            ->get();

        $routeNamesQuery = RouteModel::query()
            ->whereIn('id', $rows->pluck('route_id')->filter()->unique()->all());
        $this->routes->scopeOrganization($routeNamesQuery, $user);
        $routeNames = $routeNamesQuery->pluck('route_name', 'id');

        return $rows->map(function ($row) use ($routeNames) {
            return [
                'route_id' => (int) $row->route_id,
                'route_name' => $routeNames[(int) $row->route_id] ?? 'Route #'.$row->route_id,
                'list_date' => (string) $row->list_date,
                'order_count' => (int) $row->order_count,
                'order_total' => round((float) $row->order_total, 2),
            ];
        })->values()->all();
    }

    /**
     * Load mobile sales for one or more routes on a loading date (single query).
     *
     * @param  list<int>  $routeIds
     * @return \Illuminate\Support\Collection<int, Sale>
     */
    public function salesForRoutesOnDate(User $user, array $routeIds, string $listDate)
    {
        $routeIds = array_values(array_unique(array_filter(
            array_map('intval', $routeIds),
            fn (int $id) => $id > 0,
        )));
        if ($routeIds === []) {
            return collect();
        }

        $query = $this->baseMobileSalesQuery($user)->whereIn('route_id', $routeIds);
        $this->applyLoadingDateEquals($query, $listDate);

        return $query
            ->with(['customer:customer_num,customer_name', 'items'])
            ->orderBy('route_id')
            ->orderBy('order_num')
            ->get();
    }

    /** @return array<string, mixed> */
    public function sheetDetail(User $user, int $routeId, string $listDate): array
    {
        if ($routeId <= 0) {
            throw new InvalidArgumentException('Route is required.');
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $listDate)) {
            throw new InvalidArgumentException('Invalid loading date.');
        }

        $sales = $this->salesForRoutesOnDate($user, [$routeId], $listDate);

        $route = $this->routes->findForUser($user, $routeId);
        $orders = $this->loadingListBuilder->aggregateOrdersFromSales($sales);
        $totalAmount = round(array_sum(array_column($orders, 'subtotal')), 2);
        $orgId = $sales->first()?->organization_id !== null
            ? (int) $sales->first()->organization_id
            : ($user->organization_id ? (int) $user->organization_id : null);
        $fleet = $this->tonnage->defaultFleetForRoute($routeId, $orgId);
        $weight = $this->tonnage->applyToLines(
            collect($orders)
                ->flatMap(fn ($order) => $order['lines'] ?? [])
                ->map(fn ($line) => [
                    'product_code' => $line['product_code'] ?? null,
                    'required_qty' => $line['quantity'] ?? $line['required_qty'] ?? 0,
                ])
                ->values()
                ->all(),
            $orgId,
        );

        return [
            'loading_list' => array_merge([
                'list_date' => $listDate,
                'route_id' => $routeId,
                'route' => $route ? [
                    'id' => $route->id,
                    'route_name' => $route->route_name,
                ] : null,
                'order_count' => $sales->count(),
                'total_amount' => $totalAmount,
                'orders' => $orders,
                'driver' => $fleet['driver'],
                'vehicle' => $fleet['vehicle'],
                'total_weight_kg' => $weight['total_weight_kg'],
                'total_weight_tonnes' => $weight['total_weight_tonnes'],
                'missing_weight_count' => $weight['missing_weight_count'],
            ], $this->tonnage->vehicleCapacityFields($fleet['vehicle'])),
            'orders' => $sales->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'order_num' => $sale->order_num,
                'status' => $sale->status,
                'order_total' => (float) $sale->order_total,
                'customer_name' => $sale->customer?->customer_name,
                'delivery_date' => $sale->delivery_date,
                'organization_id' => $sale->organization_id,
            ])->values()->all(),
        ];
    }
}
