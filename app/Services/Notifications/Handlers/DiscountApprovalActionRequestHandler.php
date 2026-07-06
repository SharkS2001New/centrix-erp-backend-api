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

        return (bool) $user->is_admin
            || $this->permissions->hasPermission($user, 'sales.manage')
            || $this->permissions->hasPermission($user, 'sales.orders.approve');
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $this->discounts->approveFromActionRequest($request, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason): void
    {
        $this->discounts->rejectFromActionRequest($request, $user, $reason);
    }
}
