<?php

namespace App\Services\Fulfillment;

use App\Models\Customer;
use App\Models\Sale;
use App\Services\Erp\CapabilityGate;
use App\Services\Sales\RouteOrderScope;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RouteDashboardStatsService
{
    /** @param  Collection<int, object>  $routes */
    public function attachStats(Collection $routes, string $period, CapabilityGate $gate): Collection
    {
        $routeIds = $routes->pluck('id')->map(fn ($id) => (int) $id)->filter()->values()->all();
        if ($routeIds === []) {
            return $routes;
        }

        $includeNormalOrders = RouteOrderScope::includeNormalOrders($gate->distributionSettings());
        $bounds = $this->periodBounds($period);

        $customerCounts = Customer::query()
            ->whereIn('route_id', $routeIds)
            ->whereNull('deleted_at')
            ->groupBy('route_id')
            ->selectRaw('route_id, COUNT(*) AS customer_count')
            ->pluck('customer_count', 'route_id');

        $effectiveRoute = RouteOrderScope::effectiveRouteIdSql();
        $salesQuery = Sale::query()
            ->leftJoin(
                'customers as route_order_customers',
                'route_order_customers.customer_num',
                '=',
                'sales.customer_num',
            )
            ->whereIn(DB::raw($effectiveRoute), $routeIds)
            ->whereNotIn('sales.status', ['cancelled', 'expired', 'held'])
            ->where('sales.archived', 0)
            ->whereNull('sales.deleted_at');

        RouteOrderScope::applyChannelScope($salesQuery, $includeNormalOrders);

        if ($bounds !== null) {
            $salesQuery->whereBetween(
                DB::raw('DATE(COALESCE(sales.completed_at, sales.created_at))'),
                [$bounds['start'], $bounds['end']],
            );
        }

        $salesStats = $salesQuery
            ->groupBy(DB::raw($effectiveRoute))
            ->selectRaw("{$effectiveRoute} AS route_id, COUNT(*) AS order_count, COALESCE(SUM(sales.order_total), 0) AS sales_total")
            ->get()
            ->keyBy('route_id');

        return $routes->map(function ($route) use ($customerCounts, $salesStats) {
            $routeId = (int) $route->id;
            $stats = $salesStats->get($routeId);

            $route->customer_count = (int) ($customerCounts[$routeId] ?? 0);
            $route->orders_count = (int) ($stats->order_count ?? 0);
            $route->sales_total = (float) ($stats->sales_total ?? 0);

            return $route;
        });
    }

    /** @return array{start: string, end: string}|null */
    protected function periodBounds(string $period): ?array
    {
        $today = Carbon::today();

        return match ($period) {
            'day' => [
                'start' => $today->toDateString(),
                'end' => $today->toDateString(),
            ],
            'week' => [
                'start' => $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                'end' => $today->toDateString(),
            ],
            'month' => [
                'start' => $today->copy()->startOfMonth()->toDateString(),
                'end' => $today->toDateString(),
            ],
            'all' => null,
            default => [
                'start' => $today->toDateString(),
                'end' => $today->toDateString(),
            ],
        };
    }
}
