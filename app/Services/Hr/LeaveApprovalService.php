<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\EmployeeLeaveDay;
use App\Models\User;
use App\Services\Attendance\LeaveBalanceService;
use App\Services\Auth\UserPermissionService;
use App\Services\Hr\LeaveApprovalService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\NotificationActionUrlBuilder;
use Illuminate\Validation\ValidationException;

class LeaveApprovalService
{
    public function __construct(
        protected UserPermissionService $permissions,
        protected LeaveBalanceService $leaveBalance,
    ) {}

    public function canApprove(User $user): bool
    {
        return $this->permissions->canApproveLeaveRequests($user);
    }

    public function notifyOnCreate(User $requester, EmployeeLeaveDay $leave): void
    {
        if ($leave->approval_status !== 'pending') {
            return;
        }

        $leave->loadMissing('employee');
        $employeeName = $leave->employee?->full_name ?? 'Employee';
        $requesterName = $requester->full_name ?: $requester->username;
        $range = $leave->start_date === $leave->end_date
            ? $leave->start_date
            : "{$leave->start_date} – {$leave->end_date}";

        $actionUrl = NotificationActionUrlBuilder::for('leave_request', (int) $leave->id);

        app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'leave_request',
            'module' => 'hr_payroll',
            'reference_type' => 'employee_leave_day',
            'reference_id' => (int) $leave->id,
            'approver_permission' => 'hr.leave.approve',
            'title' => 'Leave request pending approval',
            'message' => "{$requesterName} submitted leave for {$employeeName} ({$range}).",
            'reason' => $leave->notes,
            'severity' => 'warning',
            'action_url' => $actionUrl,
            'payload' => [
                'employee_name' => $employeeName,
                'start_date' => $leave->start_date,
                'end_date' => $leave->end_date,
                'action_url' => $actionUrl,
            ],
        ]);
    }

    public function approve(EmployeeLeaveDay $leave, User $approver): EmployeeLeaveDay
    {
        if ($leave->approval_status === 'approved') {
            return $leave;
        }

        if ($leave->approval_status === 'rejected') {
            throw ValidationException::withMessages([
                'approval_status' => 'Rejected leave cannot be approved.',
            ]);
        }

        $employee = Employee::findOrFail($leave->employee_id);
        $this->leaveBalance->assertCanDeduct(
            $employee,
            $leave->deduct_from,
            (float) $leave->days_deducted,
            (int) $leave->id,
        );

        $leave->update(['approval_status' => 'approved']);

        return $leave->fresh(['employee']);
    }

    public function reject(EmployeeLeaveDay $leave, User $approver, ?string $reason = null): EmployeeLeaveDay
    {
        if ($leave->approval_status === 'approved') {
            throw ValidationException::withMessages([
                'approval_status' => 'Approved leave cannot be rejected.',
            ]);
        }

        $leave->update(['approval_status' => 'rejected']);

        return $leave->fresh(['employee']);
    }
}
