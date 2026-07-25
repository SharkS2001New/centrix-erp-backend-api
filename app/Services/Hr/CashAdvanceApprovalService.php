<?php

namespace App\Services\Hr;

use App\Models\ActionRequest;
use App\Models\Employee;
use App\Models\EmployeeCashAdvance;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\NotificationActionUrlBuilder;
use Illuminate\Validation\ValidationException;

class CashAdvanceApprovalService
{
    public function __construct(protected UserPermissionService $permissions) {}

    public function canApprove(User $user, ?ActionRequest $actionRequest = null): bool
    {
        if ($actionRequest?->assigned_to && (int) $actionRequest->assigned_to === (int) $user->id) {
            return true;
        }

        return $this->permissions->canApproveCashAdvances($user);
    }

    public function requestApproval(User $requester, EmployeeCashAdvance $advance): ActionRequest
    {
        if ($advance->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only pending cash advances can be submitted for approval.',
            ]);
        }

        $advance->loadMissing(['employee.reportsTo.user']);
        $employeeName = $advance->employee?->full_name ?? 'employee';
        $requesterName = $requester->full_name ?: $requester->username;
        $amount = number_format((float) $advance->amount, 2);
        $actionUrl = NotificationActionUrlBuilder::for('cash_advance', (int) $advance->id);
        $assignedTo = $this->resolveAssignedApproverUserId($advance, $requester);

        return app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'cash_advance',
            'module' => 'hr_payroll',
            'reference_type' => 'employee_cash_advance',
            'reference_id' => (int) $advance->id,
            'assigned_to' => $assignedTo,
            'approver_permission' => 'hr.cash_advances.approve',
            'title' => 'Cash advance approval required',
            'message' => "{$requesterName} requested KES {$amount} cash advance for {$employeeName}.",
            'reason' => $advance->notes,
            'severity' => 'warning',
            'action_url' => $actionUrl,
            'payload' => [
                'employee_name' => $employeeName,
                'amount' => round((float) $advance->amount, 2),
                'advance_date' => $advance->advance_date,
                'action_url' => $actionUrl,
                'assigned_to' => $assignedTo,
            ],
        ]);
    }

    public function approve(ActionRequest $request, User $approver): EmployeeCashAdvance
    {
        $advance = EmployeeCashAdvance::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        if ($advance->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only pending advances can be approved.',
            ]);
        }

        $advance->update(['status' => 'open']);

        return $advance->fresh('employee');
    }

    public function reject(ActionRequest $request, User $approver, ?string $reason = null): EmployeeCashAdvance
    {
        $advance = EmployeeCashAdvance::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        if ($advance->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only pending advances can be rejected.',
            ]);
        }

        $advance->update(['status' => 'cancelled']);

        return $advance->fresh('employee');
    }

    /**
     * Prefer the employee's line manager when that manager's user account
     * holds cash-advance approval rights; otherwise fall back to all approvers.
     */
    protected function resolveAssignedApproverUserId(EmployeeCashAdvance $advance, User $requester): ?int
    {
        $employee = $advance->employee;
        if (! $employee) {
            return null;
        }

        $managerUserId = $this->resolveManagerUserId($employee);
        if (! $managerUserId || (int) $managerUserId === (int) $requester->id) {
            return null;
        }

        $managerUser = User::query()
            ->where('organization_id', $requester->organization_id)
            ->where('id', $managerUserId)
            ->where('is_active', true)
            ->first();

        if (! $managerUser || ! $this->permissions->canApproveCashAdvances($managerUser)) {
            return null;
        }

        return (int) $managerUser->id;
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
}
