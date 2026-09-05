<?php

namespace App\Services\Hr;

use App\Models\EmployeeOvertime;
use App\Models\User;
use App\Services\Attendance\AttendanceDayReconciler;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\NotificationActionUrlBuilder;
use Illuminate\Validation\ValidationException;

class OvertimeApprovalService
{
    public function __construct(protected UserPermissionService $permissions) {}

    public function canApprove(User $user): bool
    {
        return $this->permissions->canApprovePendingOvertime($user);
    }

    /**
     * Create (or reuse) an ActionRequest so HR approvers get in-app + mail alerts.
     */
    public function notifyOnPending(?User $actor, EmployeeOvertime $overtime): void
    {
        if ($overtime->status !== 'pending') {
            return;
        }

        $overtime->loadMissing('employee.user');
        $requester = $this->resolveRequester($overtime, $actor);
        if ($requester === null) {
            return;
        }

        $employeeName = $overtime->employee?->full_name ?? 'Employee';
        $hours = number_format((float) $overtime->hours, 2);
        $workDate = $overtime->work_date instanceof \DateTimeInterface
            ? $overtime->work_date->format('Y-m-d')
            : (string) $overtime->work_date;
        $actionUrl = NotificationActionUrlBuilder::for('pending_overtime', (int) $overtime->id);

        app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'pending_overtime',
            'module' => 'hr_payroll',
            'reference_type' => 'employee_overtime',
            'reference_id' => (int) $overtime->id,
            'approver_permission' => 'hr.pending_overtime.approve',
            'title' => 'Overtime pending approval',
            'message' => "{$employeeName} has {$hours}h overtime on {$workDate} awaiting approval.",
            'reason' => $overtime->notes,
            'severity' => 'warning',
            'action_url' => $actionUrl,
            'payload' => [
                'employee_name' => $employeeName,
                'work_date' => $workDate,
                'hours' => round((float) $overtime->hours, 2),
                'amount' => round((float) ($overtime->amount ?? 0), 2),
                'action_url' => $actionUrl,
            ],
        ]);
    }

    public function approve(EmployeeOvertime $overtime, User $approver): EmployeeOvertime
    {
        if ($overtime->status === 'approved') {
            return $overtime;
        }

        if ($overtime->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only pending overtime can be approved.',
            ]);
        }

        $overtime->update(['status' => 'approved']);

        return $overtime->fresh(['employee']);
    }

    public function reject(EmployeeOvertime $overtime, User $approver, ?string $reason = null): void
    {
        if ($overtime->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only pending overtime can be denied.',
            ]);
        }

        app(AttendanceDayReconciler::class)->rejectPendingOvertimeAndCapClockOut($overtime);
    }

    protected function resolveRequester(EmployeeOvertime $overtime, ?User $actor): ?User
    {
        if ($actor !== null
            && (int) $actor->organization_id === (int) $overtime->organization_id
            && $actor->is_active
        ) {
            return $actor;
        }

        $linked = $overtime->employee?->user;
        if ($linked
            && (int) $linked->organization_id === (int) $overtime->organization_id
            && $linked->is_active
            && $linked->deleted_at === null
        ) {
            return $linked;
        }

        // Auto OT with no employee login: book ActionRequest under any active org user
        // who is not an OT approver so approvers still receive the alert.
        $approverIds = $this->permissions
            ->usersWhoCanApprovePendingOvertime((int) $overtime->organization_id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $nonApprover = User::query()
            ->where('organization_id', (int) $overtime->organization_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->when($approverIds !== [], fn ($q) => $q->whereNotIn('id', $approverIds))
            ->orderBy('id')
            ->first();

        if ($nonApprover) {
            return $nonApprover;
        }

        return User::query()
            ->where('organization_id', (int) $overtime->organization_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();
    }
}
