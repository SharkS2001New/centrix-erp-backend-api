<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\EmployeeOvertime;
use App\Models\User;
use App\Services\Hr\OvertimeApprovalService;
use App\Services\Notifications\Contracts\ActionRequestHandler;

class PendingOvertimeActionRequestHandler implements ActionRequestHandler
{
    public function __construct(protected OvertimeApprovalService $overtime) {}

    public function type(): string
    {
        return 'pending_overtime';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        return $this->overtime->canApprove($user);
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $row = EmployeeOvertime::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        $this->overtime->approve($row, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason, array $options = []): void
    {
        $row = EmployeeOvertime::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        $this->overtime->reject($row, $user, $reason);
    }
}
