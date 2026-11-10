<?php

namespace App\Services\Sales;

use App\Http\Controllers\Api\V1\Operations\ReportController;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Cache\OrganizationCache;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Inventory\StockValuationService;
use App\Services\Notifications\InAppNotificationService;
use App\Services\Inventory\StockCostCalculation;
use App\Support\AppTimezone;
use App\Support\EffectiveSaleDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MobileManagerService
{
    /** Days of sales_trend embedded in /manager/dashboard (matches manager app home chart). */
    public const SALES_TREND_DAYS = 10;

    /** Inventory valuation is heavy — refresh less often than sales KPIs. */
    public const INVENTORY_CACHE_SECONDS = 900;

    /** Lean home KPI cache (sales + receivables; inventory nested separately). */
    public const HOME_KPI_CACHE_SECONDS = 90;

    public function __construct(
        protected UserPermissionService $permissions,
        protected InAppNotificationService $notifications,
    ) {}

    /** @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(User $user, CapabilityGate $gate, array $filters = []): array
    {
        $hasAppAccess = $user->is_admin
            || $this->permissions->hasPermission($user, 'mobile_manager.app.access', $gate);
        $canDashboard = $hasAppAccess
            || $this->permissions->hasPermission($user, 'mobile_manager.dashboard.view', $gate);
        $canApprovals = $hasAppAccess
            || $this->permissions->hasPermission($user, 'mobile_manager.approvals.view', $gate);
        $canReports = $hasAppAccess
            || $this->permissions->hasPermission($user, 'mobile_manager.reports.view', $gate);

        $payload = [
            'pending_approval_count' => $canApprovals
                ? $this->notifications->pendingApprovalsCount($user)
                : 0,
            'unread_notification_count' => $this->notifications->unreadCount($user),
            'summary' => null,
            'reports_dashboard' => null,
        ];

        if ($canDashboard && $canReports && (
            $hasAppAccess
            || $this->permissions->hasPermission($user, 'reports.hub.view', $gate)
        )) {
            $today = AppTimezone::todayDateString();
            $fromDate = (string) ($filters['from_date'] ?? $today);
            $toDate = (string) ($filters['to_date'] ?? $today);
            $branchId = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;

            // Lean home KPIs — never rebuild the full reports dashboard (inventory scan + COGS
            // joins + top products) just to open the manager app.
            $reportsPayload = $this->leanHomeReportsPayload($user, $fromDate, $toDate, $branchId);

            if ($fromDate === $toDate && $fromDate === $today) {
                $trendFrom = Carbon::parse($today)
                    ->subDays(self::SALES_TREND_DAYS - 1)
                    ->startOfDay();
                $trendTo = Carbon::parse($today)->startOfDay();
                $orgId = (int) ($user->organization_id ?? 0);
                $resolvedBranch = $branchId;
                if (($user->access_scope ?? 'org') === 'branch' && $user->branch_id) {
                    $resolvedBranch = (int) $user->branch_id;
                }
                $salesTrend = app(ReportController::class)->leanSalesTrend(
                    $orgId > 0 ? $orgId : null,
                    $resolvedBranch,
                    $trendFrom,
                    $trendTo,
                );
                if ($salesTrend !== []) {
                    $reportsPayload['sales_trend'] = $salesTrend;
                    $reportsPayload['trend_period'] = [
                        'from_date' => $trendFrom->toDateString(),
                        'to_date' => $trendTo->toDateString(),
                        'days' => self::SALES_TREND_DAYS,
                    ];
                }
            }

            $payload['reports_dashboard'] = $reportsPayload;
            $kpis = is_array($reportsPayload['kpis'] ?? null) ? $reportsPayload['kpis'] : [];
            $payload['summary'] = [
                'total_sales' => $kpis['total_sales']['value'] ?? null,
                'gross_profit' => $kpis['gross_profit']['value'] ?? null,
                'receivables' => $kpis['receivables']['value'] ?? null,
                'inventory_value' => $kpis['inventory_value']['value'] ?? null,
            ];
        }

        return $payload;
    }

    /**
     * Fast manager-home payload: sales totals, customer balances, cached inventory.
     * Skips credit-outstanding exists-subquery, top products, channel breakdown, and COGS joins.
     *
     * @return array<string, mixed>
     */
    protected function leanHomeReportsPayload(
        User $user,
        string $fromDate,
        string $toDate,
        ?int $branchId,
    ): array {
        $orgId = (int) ($user->organization_id ?? 0);
        $resolvedBranch = $branchId;
        if (($user->access_scope ?? 'org') === 'branch' && $user->branch_id) {
            $resolvedBranch = (int) $user->branch_id;
        }

        $build = function () use ($orgId, $resolvedBranch, $fromDate, $toDate): array {
            $from = Carbon::parse($fromDate)->startOfDay();
            $to = Carbon::parse($toDate)->startOfDay();
            if ($from->gt($to)) {
                [$from, $to] = [$to, $from];
            }
            $days = max(1, $from->diffInDays($to) + 1);
            $prevTo = $from->copy()->subDay();
            $prevFrom = $prevTo->copy()->subDays($days - 1);

            $metricStatuses = app(OrderWorkflowService::class)->metricSaleStatuses();
            $salesSum = function (Carbon $start, Carbon $end) use ($orgId, $resolvedBranch, $metricStatuses): float {
                $query = DB::table('sales')
                    ->whereIn('status', $metricStatuses)
                    ->where('archived', 0)
                    ->when($orgId > 0, fn ($q) => $q->where('organization_id', $orgId))
                    ->when($resolvedBranch, fn ($q) => $q->where('branch_id', $resolvedBranch));
                EffectiveSaleDate::applyFromToDateFilter(
                    $query,
                    $start->toDateString(),
                    $end->toDateString(),
                );

                return (float) CentrixSalesScope::excludeLegacyMaterialized($query)->sum('order_total');
            };

            $totalSales = $salesSum($from, $to);
            $prevTotalSales = $salesSum($prevFrom, $prevTo);

            // Customer AR only — skip the expensive unpaid-credit exists subquery on home.
            $receivables = (float) DB::table('customers')
                ->whereNull('deleted_at')
                ->when($orgId > 0, fn ($q) => $q->where('organization_id', $orgId))
                ->when($resolvedBranch, fn ($q) => $q->where('branch_id', $resolvedBranch))
                ->sum('current_balance');

            $inventory = $this->cachedInventoryValuation(
                $orgId > 0 ? $orgId : null,
                $resolvedBranch,
            );
            $grossProfit = $this->cachedPeriodGrossProfit(
                $orgId > 0 ? $orgId : null,
                $resolvedBranch,
                $from,
                $to,
                $prevFrom,
                $prevTo,
                $metricStatuses,
                $totalSales,
                $prevTotalSales,
            );

            return [
                'period' => [
                    'from_date' => $from->toDateString(),
                    'to_date' => $to->toDateString(),
                    'previous_from_date' => $prevFrom->toDateString(),
                    'previous_to_date' => $prevTo->toDateString(),
                ],
                'kpis' => [
                    'total_sales' => [
                        'value' => $totalSales,
                        'change_pct' => $this->pctChange($totalSales, $prevTotalSales),
                    ],
                    'gross_profit' => $grossProfit,
                    'receivables' => [
                        'value' => round($receivables, 2),
                        'change_pct' => 0.0,
                    ],
                    'inventory_value' => [
                        'value' => $inventory['value'],
                        'shop_value' => $inventory['shop_value'],
                        'store_value' => $inventory['store_value'],
                        'change_pct' => 0.0,
                    ],
                ],
                'sales_trend' => [],
                'top_products' => [],
                'sales_by_channel' => [],
                'lean_home' => true,
            ];
        };

        if ($orgId <= 0) {
            return $build();
        }

        return OrganizationCache::remember(
            $orgId,
            sprintf(
                'manager.home_kpis:v%d:b%d:%s:%s',
                OrganizationCache::reportsDashboardVersion($orgId),
                (int) $resolvedBranch,
                $fromDate,
                $toDate,
            ),
            self::HOME_KPI_CACHE_SECONDS,
            $build,
        );
    }

    /**
     * @return array{value: float, shop_value: float, store_value: float}
     */
    protected function cachedInventoryValuation(?int $orgId, ?int $branchId): array
    {
        $empty = ['value' => 0.0, 'shop_value' => 0.0, 'store_value' => 0.0];
        try {
            $summary = app(StockValuationService::class)->summarizeCached(
                $orgId,
                $branchId,
                self::INVENTORY_CACHE_SECONDS,
            );

            return [
                'value' => (float) ($summary['value'] ?? 0),
                'shop_value' => (float) ($summary['shop_value'] ?? 0),
                'store_value' => (float) ($summary['store_value'] ?? 0),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * Gross profit (sales − COGS) cached separately — COGS join is the slow part.
     *
     * @param  list<string>  $metricStatuses
     * @return array{value: float, change_pct: float}
     */
    protected function cachedPeriodGrossProfit(
        ?int $orgId,
        ?int $branchId,
        Carbon $from,
        Carbon $to,
        Carbon $prevFrom,
        Carbon $prevTo,
        array $metricStatuses,
        float $totalSales,
        float $prevTotalSales,
    ): array {
        $compute = function () use (
            $orgId,
            $branchId,
            $from,
            $to,
            $prevFrom,
            $prevTo,
            $metricStatuses,
            $totalSales,
            $prevTotalSales,
        ): array {
            $cogsFor = function (Carbon $start, Carbon $end) use ($orgId, $branchId, $metricStatuses): float {
                return (float) DB::table('sale_items as si')
                    ->join('sales as cs', 'cs.id', '=', 'si.sale_id')
                    ->join('products as p', function ($join) {
                        $join->on('p.product_code', '=', 'si.product_code')
                            ->on('p.organization_id', '=', 'cs.organization_id');
                    })
                    ->leftJoin('uoms as uom', 'uom.id', '=', 'p.unit_id')
                    ->whereIn('cs.status', $metricStatuses)
                    ->where('cs.archived', 0)
                    ->when($orgId, fn ($q) => $q->where('cs.organization_id', $orgId))
                    ->when($branchId, fn ($q) => $q->where('cs.branch_id', $branchId))
                    ->tap(fn ($q) => EffectiveSaleDate::applyFromToDateFilter(
                        $q,
                        $start->toDateString(),
                        $end->toDateString(),
                        'cs',
                    ))
                    ->tap(fn ($q) => CentrixSalesScope::excludeLegacyMaterialized($q, 'cs'))
                    ->selectRaw(
                        'COALESCE(SUM(' . StockCostCalculation::costValueSqlExpression(
                            'si.quantity',
                            'COALESCE(p.last_cost_price, 0)',
                            'uom',
                        ) . '), 0) as total_cost',
                    )
                    ->value('total_cost');
            };

            try {
                $gross = $totalSales - $cogsFor($from, $to);
                $prevGross = $prevTotalSales - $cogsFor($prevFrom, $prevTo);
            } catch (\Throwable) {
                $gross = $totalSales;
                $prevGross = $prevTotalSales;
            }

            return [
                'value' => round($gross, 2),
                'change_pct' => $this->pctChange($gross, $prevGross),
            ];
        };

        if (! $orgId || $orgId <= 0) {
            return $compute();
        }

        return OrganizationCache::remember(
            $orgId,
            sprintf(
                'manager.gross_profit:b%d:%s:%s',
                (int) $branchId,
                $from->toDateString(),
                $to->toDateString(),
            ),
            300,
            $compute,
        );
    }

    protected function pctChange(float $current, float $previous): float
    {
        if (abs($previous) < 0.00001) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
