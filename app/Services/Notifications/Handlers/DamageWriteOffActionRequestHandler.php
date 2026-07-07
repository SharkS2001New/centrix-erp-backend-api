<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\User;
use App\Services\Inventory\DamageApprovalService;
use App\Services\Notifications\Contracts\ActionRequestHandler;

class DamageWriteOffActionRequestHandler implements ActionRequestHandler
{
    public function __construct(protected DamageApprovalService $damages) {}

    public function type(): string
    {
        return 'damage_write_off';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        return $this->damages->canApprove($user);
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $this->damages->approve($request, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason, array $options = []): void
    {
        // No stock deduction on reject.
    }
}
