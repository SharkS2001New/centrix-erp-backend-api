<?php

namespace App\Services\Hr;

use App\Models\ActionRequest;
use App\Models\EmployeeCashAdvance;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\NotificationActionUrlBuilder;
use Illuminate\Validation\ValidationException;

class CashAdvanceApprovalService
{
    public function __construct(protected UserPermissionService $permissions) {}

    public function canApprove(User $user): bool
    {
        return (bool) $user->is_admin
            || $this->permissions->hasPermission($user, 'hr.cash_advances.approve')
            || $this->permissions->hasPermission($user, 'hr.manage');
    }

    public function requestApproval(User $requester, EmployeeCashAdvance $advance): ActionRequest
    {
        if ($advance->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only pending cash advances can be submitted for approval.',
            ]);
        }

        $advance->loadMissing('employee');
        $employeeName = $advance->employee?->full_name ?? 'employee';
        $requesterName = $requester->full_name ?: $requester->username;
        $amount = number_format((float) $advance->amount, 2);
        $actionUrl = NotificationActionUrlBuilder::for('cash_advance', (int) $advance->id);

        return app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'cash_advance',
            'module' => 'hr_payroll',
            'reference_type' => 'employee_cash_advance',
            'reference_id' => (int) $advance->id,
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
}
