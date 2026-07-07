<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\User;
use App\Services\Notifications\Contracts\ActionRequestHandler;
use App\Services\Purchasing\LpoApprovalService;

class LpoApprovalActionRequestHandler implements ActionRequestHandler
{
    public function __construct(
        protected LpoApprovalService $lpos,
    ) {}

    public function type(): string
    {
        return 'lpo_approval';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        return $this->lpos->canApprove($user);
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $this->lpos->approve($request, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason, array $options = []): void
    {
        $this->lpos->reject($request, $user, $reason);
    }
}
