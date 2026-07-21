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
    public function __construct(
        protected UserPermissionService $permissions,
        protected InAppNotificationService $notifications,
    ) {}

    /** @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(User $user, CapabilityGate $gate, array $filters = []): array
    {
        $canDashboard = $user->is_admin
            || $this->permissions->hasPermission($user, 'mobile_manager.dashboard.view', $gate)
            || $this->permissions->hasPermission($user, 'mobile_manager.app.access', $gate);
        $canApprovals = $user->is_admin
            || $this->permissions->hasPermission($user, 'mobile_manager.approvals.view', $gate)
            || $this->permissions->hasPermission($user, 'mobile_manager.app.access', $gate);
        $canReports = $user->is_admin
            || $this->permissions->hasPermission($user, 'mobile_manager.reports.view', $gate)
            || $this->permissions->hasPermission($user, 'mobile_manager.app.access', $gate);

        $payload = [
            'pending_approval_count' => $canApprovals
                ? $this->notifications->pendingApprovalsCount($user)
                : 0,
            'unread_notification_count' => $this->notifications->unreadCount($user),
            'summary' => null,
            'reports_dashboard' => null,
        ];

        if ($canDashboard && $canReports && (
            $this->permissions->hasPermission($user, 'reports.hub.view', $gate)
            || $this->permissions->hasPermission($user, 'mobile_manager.app.access', $gate)
        )) {
            $today = \App\Support\AppTimezone::todayDateString();
            $reportsRequest = Request::create('/api/v1/reports/dashboard', 'GET', array_filter([
                'from_date' => $filters['from_date'] ?? $today,
                'to_date' => $filters['to_date'] ?? $today,
                'branch_id' => $filters['branch_id'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''));
            $reportsRequest->setUserResolver(fn () => $user);
            $reportsPayload = app(ReportController::class)->dashboard($reportsRequest)->getData(true);
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
}
