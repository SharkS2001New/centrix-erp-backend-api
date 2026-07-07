<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\User;
use App\Services\Inventory\StockTransferApprovalService;
use App\Services\Notifications\Contracts\ActionRequestHandler;

class StockTransferActionRequestHandler implements ActionRequestHandler
{
    public function __construct(
        protected StockTransferApprovalService $transfers,
    ) {}

    public function type(): string
    {
        return 'stock_transfer';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        return $this->transfers->canDirectTransfer($user);
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $this->transfers->applyFromActionRequest($request, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason, array $options = []): void
    {
        // No stock movement on reject.
    }
}
