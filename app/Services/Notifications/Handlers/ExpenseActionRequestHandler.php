<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\User;
use App\Services\Accounting\ExpenseApprovalService;
use App\Services\Notifications\Contracts\ActionRequestHandler;

class ExpenseActionRequestHandler implements ActionRequestHandler
{
    public function __construct(protected ExpenseApprovalService $expenses) {}

    public function type(): string
    {
        return 'expense_action';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        return $this->expenses->canApprove($user);
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $this->expenses->apply($request, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason): void
    {
        // No accounting movement on reject.
    }
}
