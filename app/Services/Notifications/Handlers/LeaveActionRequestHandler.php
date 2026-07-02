<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\EmployeeLeaveDay;
use App\Models\User;
use App\Services\Hr\LeaveApprovalService;
use App\Services\Notifications\Contracts\ActionRequestHandler;

class LeaveActionRequestHandler implements ActionRequestHandler
{
    public function __construct(
        protected LeaveApprovalService $leave,
    ) {}

    public function type(): string
    {
        return 'leave_request';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        return $this->leave->canApprove($user);
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $leave = EmployeeLeaveDay::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        $this->leave->approve($leave, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason): void
    {
        $leave = EmployeeLeaveDay::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        $this->leave->reject($leave, $user, $reason);
    }
}
