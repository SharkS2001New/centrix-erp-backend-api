<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\User;
use App\Services\Notifications\Contracts\ActionRequestHandler;
use App\Services\Sales\OrderCancellationRequestService;

class OrderCancellationActionRequestHandler implements ActionRequestHandler
{
    public function __construct(
        protected OrderCancellationRequestService $cancellations,
    ) {}

    public function type(): string
    {
        return 'order_cancel';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        return $this->cancellations->canApproveCancellation($user, $request);
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $this->cancellations->cancelFromActionRequest($request, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason, array $options = []): void
    {
        // Order stays active.
    }
}
