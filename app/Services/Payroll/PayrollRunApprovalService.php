<?php

namespace App\Services\Payroll;

use App\Models\ActionRequest;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\NotificationActionUrlBuilder;
use Illuminate\Validation\ValidationException;

class PayrollRunApprovalService
{
    public function __construct(
        protected UserPermissionService $permissions,
    ) {}

    public function canApprove(User $user): bool
    {
        return $this->permissions->canApprovePayrollRuns($user);
    }

    public function requestApproval(User $requester, PayrollRun $run): ActionRequest
    {
        if ($run->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => 'Only payroll runs awaiting approval can be submitted.',
            ]);
        }

        $run->loadMissing('payPeriod');
        $period = $run->payPeriod?->period_name ?? $run->run_date;
        $requesterName = $requester->full_name ?: $requester->username;
        $actionUrl = NotificationActionUrlBuilder::for('payroll_run', (int) $run->id);

        return app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'payroll_run',
            'module' => 'hr_payroll',
            'reference_type' => 'payroll_run',
            'reference_id' => (int) $run->id,
            'approver_permission' => 'hr.payroll.approve',
            'title' => 'Payroll run approval required',
            'message' => "{$requesterName} submitted payroll for {$period} for approval.",
            'severity' => 'warning',
            'action_url' => $actionUrl,
            'payload' => [
                'period' => $period,
                'run_date' => $run->run_date,
                'total_net' => round((float) ($run->total_net ?? 0), 2),
                'action_url' => $actionUrl,
            ],
        ]);
    }

    public function approve(ActionRequest $request, User $approver): PayrollRun
    {
        $run = PayrollRun::query()
            ->whereKey((int) $request->reference_id)
            ->firstOrFail();

        if ($run->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => 'Only payroll runs awaiting approval can be approved.',
            ]);
        }

        $run->update([
            'status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        return $run->fresh(['payPeriod', 'approvedByUser']);
    }

    public function reject(ActionRequest $request, User $approver, ?string $reason = null): PayrollRun
    {
        $run = PayrollRun::query()
            ->whereKey((int) $request->reference_id)
            ->firstOrFail();

        if ($run->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => 'Only payroll runs awaiting approval can be rejected.',
            ]);
        }

        $run->update(['status' => 'void']);

        return $run->fresh('payPeriod');
    }
}
