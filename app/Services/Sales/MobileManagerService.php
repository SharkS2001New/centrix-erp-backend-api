<?php

namespace App\Services\Sales;

use App\Http\Controllers\Api\V1\Operations\ReportController;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Notifications\InAppNotificationService;
use Illuminate\Http\Request;

class MobileManagerService
{
    /** Days of sales_trend embedded in /manager/dashboard (matches manager app home chart). */
    public const SALES_TREND_DAYS = 10;

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
            $today = \App\Support\AppTimezone::todayDateString();
            $fromDate = (string) ($filters['from_date'] ?? $today);
            $toDate = (string) ($filters['to_date'] ?? $today);
            $branchId = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;

            $reportsPayload = $this->cachedReportsDashboard($user, $fromDate, $toDate, $branchId);

            // Embed multi-day sales_trend via lean SUM queries — never rebuild the full
            // dashboard (inventory/receivables/top products) just for the home chart.
            if ($fromDate === $toDate && $fromDate === $today) {
                $trendFrom = \Carbon\Carbon::parse($today)
                    ->subDays(self::SALES_TREND_DAYS - 1)
                    ->startOfDay();
                $trendTo = \Carbon\Carbon::parse($today)->startOfDay();
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
     * @return array<string, mixed>
     */
    protected function cachedReportsDashboard(
        User $user,
        string $fromDate,
        string $toDate,
        ?int $branchId,
    ): array {
        // ReportController::dashboard already OrganizationCache::remember(120s) on the
        // shared reportsDashboardCacheKey — reuse that (no per-user cache miss storm).
        return $this->fetchReportsDashboard($user, $fromDate, $toDate, $branchId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchReportsDashboard(
        User $user,
        string $fromDate,
        string $toDate,
        ?int $branchId,
    ): array {
        $reportsRequest = Request::create('/api/v1/reports/dashboard', 'GET', array_filter([
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'branch_id' => $branchId,
        ], fn ($value) => $value !== null && $value !== ''));
        $reportsRequest->setUserResolver(fn () => $user);

        return app(ReportController::class)->dashboard($reportsRequest)->getData(true);
    }
}
