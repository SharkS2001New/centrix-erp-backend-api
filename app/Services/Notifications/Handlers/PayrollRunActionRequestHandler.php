<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\User;
use App\Services\Notifications\Contracts\ActionRequestHandler;
use App\Services\Payroll\PayrollRunApprovalService;

class PayrollRunActionRequestHandler implements ActionRequestHandler
{
    public function __construct(protected PayrollRunApprovalService $payroll) {}

    public function type(): string
    {
        return 'payroll_run';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        return $this->payroll->canApprove($user);
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $this->payroll->approve($request, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason, array $options = []): void
    {
        $this->payroll->reject($request, $user, $reason);
    }
}
