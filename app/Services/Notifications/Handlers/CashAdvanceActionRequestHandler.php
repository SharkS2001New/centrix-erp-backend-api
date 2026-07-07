<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\User;
use App\Services\Hr\CashAdvanceApprovalService;
use App\Services\Notifications\Contracts\ActionRequestHandler;

class CashAdvanceActionRequestHandler implements ActionRequestHandler
{
    public function __construct(protected CashAdvanceApprovalService $advances) {}

    public function type(): string
    {
        return 'cash_advance';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        return $this->advances->canApprove($user);
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $this->advances->approve($request, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason, array $options = []): void
    {
        $this->advances->reject($request, $user, $reason);
    }
}
