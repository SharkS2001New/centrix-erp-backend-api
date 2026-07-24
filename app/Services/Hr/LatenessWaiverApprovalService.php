<?php

namespace App\Services\Hr;

use App\Models\ActionRequest;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\LatenessWaiverRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceDayReconciler;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\NotificationActionUrlBuilder;
use App\Services\Payroll\PayrollCycleSettlementService;
use Illuminate\Validation\ValidationException;

class LatenessWaiverApprovalService
{
    public function __construct(
        protected UserPermissionService $permissions,
        protected AttendanceDayReconciler $reconciler,
    ) {}

    public function canApproveByPermission(User $user): bool
    {
        return $this->permissions->canApproveLatenessWaivers($user);
    }

    public function canApprove(User $user, ?ActionRequest $actionRequest = null, ?LatenessWaiverRequest $waiver = null): bool
    {
        if ($actionRequest?->assigned_to && (int) $actionRequest->assigned_to === (int) $user->id) {
            return true;
        }
        if ($waiver?->assigned_manager_user_id && (int) $waiver->assigned_manager_user_id === (int) $user->id) {
            return true;
        }

        return $this->canApproveByPermission($user);
    }

    /**
     * Submit a waive (or undo) request for manager / HR approval. Does not change payroll hours yet.
     */
    public function submit(User $requester, EmployeeAttendance $attendance, bool $waive, ?string $reason = null): LatenessWaiverRequest
    {
        PayrollCycleSettlementService::assertNotPayrollLocked(
            $attendance->payroll_run_id ? (int) $attendance->payroll_run_id : null,
            'attendance record',
        );

        if ((int) ($attendance->late_minutes ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'lateness_waived' => ['This attendance record has no late minutes to waive.'],
            ]);
        }

        if ($waive && (bool) $attendance->lateness_waived) {
            throw ValidationException::withMessages([
                'lateness_waived' => ['Lateness is already waived on this record.'],
            ]);
        }

        if (! $waive && ! (bool) $attendance->lateness_waived) {
            throw ValidationException::withMessages([
                'lateness_waived' => ['There is no lateness waiver to undo on this record.'],
            ]);
        }

        $existing = LatenessWaiverRequest::query()
            ->where('employee_attendance_id', $attendance->id)
            ->where('status', 'pending')
            ->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'lateness_waived' => ['A lateness waiver request is already pending approval for this day.'],
            ]);
        }

        $attendance->loadMissing(['employee.reportsTo.user', 'employee.user']);
        $employee = $attendance->employee;
        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => ['Attendance employee not found.'],
            ]);
        }

        $managerUserId = $this->resolveManagerUserId($employee);
        if ($managerUserId && (int) $managerUserId === (int) $requester->id) {
            // Requester is the manager — still create a request but leave unassigned so HR approvers are notified,
            // unless they have approve permission (then they can self-approve via permission path after notify).
            $managerUserId = null;
        }

        $date = $attendance->attendance_date instanceof \Carbon\Carbon
            ? $attendance->attendance_date->toDateString()
            : (string) $attendance->attendance_date;

        $request = LatenessWaiverRequest::query()->create([
            'organization_id' => $attendance->organization_id,
            'branch_id' => $attendance->branch_id,
            'employee_attendance_id' => $attendance->id,
            'employee_id' => $attendance->employee_id,
            'attendance_date' => $date,
            'late_minutes' => (int) $attendance->late_minutes,
            'reason' => $reason,
            'status' => 'pending',
            'waive' => $waive,
            'requested_by' => $requester->id,
            'requested_at' => now(),
            'assigned_manager_user_id' => $managerUserId,
        ]);

        $this->notifyApprovers($requester, $request, $employee);

        return $request->fresh(['employee', 'attendance', 'assignedManager', 'requester']);
    }

    public function approve(LatenessWaiverRequest $waiver, User $approver, ?string $reviewNotes = null): LatenessWaiverRequest
    {
        if (! $waiver->isPending()) {
            throw ValidationException::withMessages([
                'status' => ['Only pending waiver requests can be approved.'],
            ]);
        }

        if (! $this->canApprove($approver, null, $waiver)) {
            throw ValidationException::withMessages([
                'status' => ['You are not allowed to approve this lateness waiver.'],
            ]);
        }

        $attendance = EmployeeAttendance::query()->findOrFail($waiver->employee_attendance_id);
        PayrollCycleSettlementService::assertNotPayrollLocked(
            $attendance->payroll_run_id ? (int) $attendance->payroll_run_id : null,
            'attendance record',
        );

        $this->reconciler->setLatenessWaiver(
            $attendance,
            (bool) $waiver->waive,
            $waiver->reason,
            $approver->id,
        );

        $waiver->update([
            'status' => 'approved',
            'reviewed_by' => $approver->id,
            'reviewed_at' => now(),
            'review_notes' => $reviewNotes,
        ]);

        return $waiver->fresh(['employee', 'attendance', 'reviewer']);
    }

    public function reject(LatenessWaiverRequest $waiver, User $approver, ?string $reason = null): LatenessWaiverRequest
    {
        if (! $waiver->isPending()) {
            throw ValidationException::withMessages([
                'status' => ['Only pending waiver requests can be rejected.'],
            ]);
        }

        if (! $this->canApprove($approver, null, $waiver)) {
            throw ValidationException::withMessages([
                'status' => ['You are not allowed to reject this lateness waiver.'],
            ]);
        }

        $waiver->update([
            'status' => 'rejected',
            'reviewed_by' => $approver->id,
            'reviewed_at' => now(),
            'review_notes' => $reason,
        ]);

        return $waiver->fresh(['employee', 'attendance', 'reviewer']);
    }

    public function approveFromActionRequest(ActionRequest $request, User $approver): LatenessWaiverRequest
    {
        $waiver = LatenessWaiverRequest::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        return $this->approve($waiver, $approver);
    }

    public function rejectFromActionRequest(ActionRequest $request, User $approver, ?string $reason = null): LatenessWaiverRequest
    {
        $waiver = LatenessWaiverRequest::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        return $this->reject($waiver, $approver, $reason);
    }

    protected function resolveManagerUserId(Employee $employee): ?int
    {
        $manager = $employee->relationLoaded('reportsTo')
            ? $employee->reportsTo
            : $employee->reportsTo()->with('user')->first();

        if (! $manager) {
            return null;
        }

        $manager->loadMissing('user');
        $userId = $manager->user_id ? (int) $manager->user_id : null;

        return $userId && $userId > 0 ? $userId : null;
    }

    protected function notifyApprovers(User $requester, LatenessWaiverRequest $waiver, Employee $employee): void
    {
        $employeeName = $employee->full_name ?: trim($employee->first_name.' '.$employee->last_name);
        $requesterName = $requester->full_name ?: $requester->username;
        $date = $waiver->attendance_date instanceof \Carbon\Carbon
            ? $waiver->attendance_date->toDateString()
            : (string) $waiver->attendance_date;
        $action = $waiver->waive ? 'waive' : 'undo waiver for';
        $actionUrl = NotificationActionUrlBuilder::for('lateness_waiver', (int) $waiver->id);

        app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'lateness_waiver',
            'module' => 'hr_payroll',
            'reference_type' => 'lateness_waiver_request',
            'reference_id' => (int) $waiver->id,
            'assigned_to' => $waiver->assigned_manager_user_id,
            'approver_permission' => 'hr.attendance.waive.approve',
            'title' => $waiver->waive ? 'Lateness waiver needs approval' : 'Undo lateness waiver needs approval',
            'message' => "{$requesterName} requested to {$action} {$waiver->late_minutes}m late for {$employeeName} on {$date}.",
            'reason' => $waiver->reason,
            'severity' => 'warning',
            'action_url' => $actionUrl,
            'payload' => [
                'employee_name' => $employeeName,
                'attendance_date' => $date,
                'late_minutes' => (int) $waiver->late_minutes,
                'waive' => (bool) $waiver->waive,
                'action_url' => $actionUrl,
            ],
        ]);
    }
}
