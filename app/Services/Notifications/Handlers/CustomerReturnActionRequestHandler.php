<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\CustomerReturn;
use App\Models\User;
use App\Services\Notifications\Contracts\ActionRequestHandler;
use App\Services\Sales\CustomerReturnApprovalService;
use App\Services\Sales\CustomerReturnService;

class CustomerReturnActionRequestHandler implements ActionRequestHandler
{
    public function __construct(
        protected CustomerReturnService $returns,
        protected CustomerReturnApprovalService $approval,
    ) {}

    public function type(): string
    {
        return 'customer_return';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        if ((int) $user->organization_id !== (int) $request->organization_id) {
            return false;
        }

        return $this->approval->canApprove($user);
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $return = CustomerReturn::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        $this->returns->approve($return, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason): void
    {
        $return = CustomerReturn::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        $this->returns->reject($return, $user, $reason);
    }
}
