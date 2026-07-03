<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\User;
use App\Services\Inventory\StockTakeApprovalService;
use App\Services\Notifications\Contracts\ActionRequestHandler;

class StockTakeCompletionActionRequestHandler implements ActionRequestHandler
{
    public function __construct(protected StockTakeApprovalService $stockTakes) {}

    public function type(): string
    {
        return 'stock_take_completion';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        return $this->stockTakes->canApprove($user);
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $this->stockTakes->approve($request, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason): void
    {
        // Variances are not posted on reject.
    }
}
