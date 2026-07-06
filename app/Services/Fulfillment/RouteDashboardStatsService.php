<?php

namespace App\Services\Fulfillment;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Erp\CapabilityGate;
use App\Services\Sales\CentrixSalesScope;
use App\Services\Sales\RouteOrderScope;
use App\Support\EffectiveSaleDate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RouteDashboardStatsService
{
    public function __construct(protected UserAccessService $access) {}

    /** @param  Collection<int, object>  $routes */
    public function attachStats(Collection $routes, string $period, CapabilityGate $gate, User $user): Collection
    {
        $routeIds = $routes->pluck('id')->map(fn ($id) => (int) $id)->filter()->values()->all();
        if ($routeIds === []) {
            return $routes;
        }

        $distributionSettings = $gate->distributionSettings();
        $includeNormalOrders = RouteOrderScope::includeNormalOrders($distributionSettings);
        $bounds = $this->periodBounds($period);

        $customerCounts = Customer::query()
            ->whereIn('route_id', $routeIds)
            ->whereNull('deleted_at');
        $this->access->scopeOrganization($customerCounts, $user);
        $customerCounts = $customerCounts
            ->groupBy('route_id')
            ->selectRaw('route_id, COUNT(*) AS customer_count')
            ->pluck('customer_count', 'route_id');

        $salesQuery = Sale::query();
        $this->access->scopeOrganization($salesQuery, $user, 'sales.organization_id');
        $this->access->scopeBranchIfLimited($salesQuery, $user, 'sales.branch_id');

        RouteOrderScope::applyForLoadingList($salesQuery, $includeNormalOrders);
        CentrixSalesScope::excludeLegacyMaterialized($salesQuery, 'sales');

        $salesQuery
            ->whereIn(DB::raw(RouteOrderScope::effectiveRouteIdSql()), $routeIds)
            ->whereNotIn('sales.status', ['cancelled', 'expired', 'held'])
            ->where('sales.archived', 0)
            ->whereNull('sales.deleted_at');

        if ($bounds !== null) {
            EffectiveSaleDate::applyRange($salesQuery, $bounds['start'], $bounds['end']);
        }

        $effectiveRoute = RouteOrderScope::effectiveRouteIdSql();
        $salesStats = $salesQuery
            ->groupBy(DB::raw($effectiveRoute))
            ->selectRaw("{$effectiveRoute} AS route_id, COUNT(*) AS order_count, COALESCE(SUM(sales.order_total), 0) AS sales_total")
            ->get()
            ->keyBy(fn ($row) => (int) $row->route_id);

        return $routes->map(function ($route) use ($customerCounts, $salesStats) {
            $routeId = (int) $route->id;
            $stats = $salesStats->get($routeId);

            $route->setAttribute('customer_count', (int) ($customerCounts[$routeId] ?? $customerCounts[(string) $routeId] ?? 0));
            $route->setAttribute('orders_count', (int) ($stats->order_count ?? 0));
            $route->setAttribute('sales_total', (float) ($stats->sales_total ?? 0));

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
