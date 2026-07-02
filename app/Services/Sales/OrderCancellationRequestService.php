<?php

namespace App\Services\Sales;

use App\Models\ActionRequest;
use App\Models\Sale;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Validation\ValidationException;

class OrderCancellationRequestService
{
    public function __construct(
        protected SaleCancellationService $cancellations,
        protected UserPermissionService $permissions,
        protected ErpContext $erp,
    ) {}

    public function canDirectCancel(User $user): bool
    {
        return (bool) $user->is_admin
            || $this->permissions->hasPermission($user, 'sales.manage');
    }

    public function canApproveCancellation(User $user, ActionRequest $request): bool
    {
        if ((int) $user->organization_id !== (int) $request->organization_id) {
            return false;
        }

        return (bool) $user->is_admin
            || $this->permissions->hasPermission($user, 'sales.manage')
            || $this->permissions->hasPermission($user, 'sales.orders.approve');
    }

    public function requestCancellation(User $user, Sale $sale, string $reason, CapabilityGate $gate): ActionRequest
    {
        if (strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages([
                'reason' => 'Cancellation reason must be at least 3 characters.',
            ]);
        }

        if (! $this->cancellations->cancellationApprovalEnabled($gate)) {
            throw ValidationException::withMessages([
                'authorization' => 'Order cancellation requests are not enabled. Contact a manager to cancel this order.',
            ]);
        }

        if ($this->canDirectCancel($user)) {
            throw ValidationException::withMessages([
                'authorization' => 'You can cancel this order directly from the order screen.',
            ]);
        }

        $workflow = OrderWorkflowService::forGate($gate);
        if (! $workflow->canTransition((string) $sale->status, 'cancelled', $sale->channel)) {
            throw ValidationException::withMessages([
                'status' => 'This order cannot be cancelled.',
            ]);
        }

        if (! $workflow->orderCancellationEnabled()) {
            throw ValidationException::withMessages([
                'status' => 'Order cancellation is disabled for this organization.',
            ]);
        }

        $requesterName = $user->full_name ?: $user->username;
        $orderLabel = $sale->order_num ? 'Order #'.$sale->order_num : 'Order #'.$sale->id;

        return app(ActionRequestService::class)->requestApproval($user, [
            'type' => 'order_cancel',
            'module' => 'sales',
            'reference_type' => 'sale',
            'reference_id' => (int) $sale->id,
            'approver_permission' => 'sales.orders.approve',
            'title' => 'Order cancellation request',
            'message' => "{$requesterName} requested cancellation of {$orderLabel}.",
            'reason' => $reason,
            'severity' => 'danger',
            'action_url' => '/sales/orders/'.$sale->id,
            'payload' => [
                'order_num' => $sale->order_num,
                'order_total' => (float) $sale->order_total,
                'action_url' => '/sales/orders/'.$sale->id,
            ],
        ]);
    }

    public function cancelFromActionRequest(ActionRequest $request, User $approver): Sale
    {
        $sale = Sale::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        $gate = $this->erp->gateForUser($approver);

        return $this->cancellations->cancelSale($sale, $approver, $gate);
    }
}
