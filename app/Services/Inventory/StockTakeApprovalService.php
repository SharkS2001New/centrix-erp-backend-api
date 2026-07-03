<?php

namespace App\Services\Inventory;

use App\Http\Controllers\Api\V1\Operations\StockTakeOperationsController;
use App\Models\ActionRequest;
use App\Models\Branch;
use App\Models\StockTakeSession;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\NotificationActionUrlBuilder;
use Illuminate\Validation\ValidationException;

class StockTakeApprovalService
{
    public function __construct(protected UserPermissionService $permissions) {}

    public function canApprove(User $user): bool
    {
        return (bool) $user->is_admin
            || $this->permissions->hasPermission($user, 'inventory.stock_take.approve')
            || $this->permissions->hasPermission($user, 'inventory.manage');
    }

    public function requestCompletion(User $requester, StockTakeSession $session): ActionRequest
    {
        if ($session->status === 'completed') {
            throw ValidationException::withMessages(['status' => 'Session already completed.']);
        }

        $requesterName = $requester->full_name ?: $requester->username;
        $actionUrl = NotificationActionUrlBuilder::for('stock_take', (int) $session->id);

        return app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'stock_take_completion',
            'module' => 'inventory',
            'reference_type' => 'stock_take_session',
            'reference_id' => (int) $session->id,
            'approver_permission' => 'inventory.stock_take.approve',
            'title' => 'Stock take completion approval required',
            'message' => "{$requesterName} requested approval to complete stock take #{$session->id}.",
            'severity' => 'warning',
            'action_url' => $actionUrl,
            'payload' => [
                'branch_id' => (int) $session->branch_id,
                'stock_location' => $session->stock_location,
                'action_url' => $actionUrl,
            ],
        ]);
    }

    public function approve(ActionRequest $request, User $approver): StockTakeSession
    {
        $branchIds = Branch::query()
            ->where('organization_id', $request->organization_id)
            ->pluck('id');

        $session = StockTakeSession::query()
            ->where('id', (int) $request->reference_id)
            ->whereIn('branch_id', $branchIds)
            ->firstOrFail();

        return app(StockTakeOperationsController::class)->completeStockTakeSession($session, $approver);
    }
}
