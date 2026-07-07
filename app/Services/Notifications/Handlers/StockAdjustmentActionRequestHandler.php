<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\User;
use App\Services\Inventory\StockAdjustmentApprovalService;
use App\Services\Notifications\Contracts\ActionRequestHandler;

class StockAdjustmentActionRequestHandler implements ActionRequestHandler
{
    public function __construct(
        protected StockAdjustmentApprovalService $adjustments,
    ) {}

    public function type(): string
    {
        return 'stock_adjustment';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        return $this->adjustments->canDirectAdjust($user);
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $this->adjustments->applyFromActionRequest($request, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason, array $options = []): void
    {
        // No stock movement on reject.
    }
}
