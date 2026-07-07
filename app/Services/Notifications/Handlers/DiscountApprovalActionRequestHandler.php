<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\Contracts\ActionRequestHandler;
use App\Services\Sales\DiscountApprovalService;

class DiscountApprovalActionRequestHandler implements ActionRequestHandler
{
    public function __construct(
        protected DiscountApprovalService $discounts,
        protected UserPermissionService $permissions,
    ) {}

    public function type(): string
    {
        return 'discount';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        if ((int) $user->organization_id !== (int) $request->organization_id) {
            return false;
        }

        return $this->permissions->canApproveDiscountRequests($user);
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $this->discounts->approveFromActionRequest($request, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason, array $options = []): void
    {
        $this->discounts->rejectFromActionRequest($request, $user, $reason, $options);
    }
}
