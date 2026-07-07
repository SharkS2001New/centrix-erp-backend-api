<?php

namespace App\Services\Sales;

use App\Models\CustomerReturn;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\NotificationActionUrlBuilder;
use App\Services\Returns\ReturnProofService;

class CustomerReturnApprovalService
{
    public function __construct(
        protected UserPermissionService $permissions,
        protected ReturnProofService $proofService,
    ) {}

    public function canApprove(User $user): bool
    {
        return $this->permissions->canApproveCustomerReturns($user);
    }

    public function notifyOnCreate(User $requester, CustomerReturn $return): void
    {
        if ($return->status !== 'pending') {
            return;
        }

        $return->loadMissing(['customer', 'sale']);
        $requesterName = $requester->full_name ?: $requester->username;
        $customerName = $return->customer?->customer_name ?? 'Walk-in customer';
        $actionUrl = NotificationActionUrlBuilder::for('customer_return', (int) $return->id);
        $returnReason = trim((string) ($return->reason ?? ''));
        $proof = $this->proofService->meta($return, '/customer-returns/'.$return->id.'/proof/file');

        $message = "{$requesterName} submitted return {$return->return_no} for {$customerName} (KES "
            .number_format((float) $return->total_amount, 2).').';
        if ($returnReason !== '') {
            $message .= " Reason: {$returnReason}.";
        }
        if ($proof !== null) {
            $message .= ' Proof attached.';
        }

        app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'customer_return',
            'module' => 'sales',
            'reference_type' => 'customer_return',
            'reference_id' => (int) $return->id,
            'approver_permission' => 'sales.manage',
            'title' => 'Customer return approval required',
            'message' => $message,
            'reason' => $returnReason !== '' ? $returnReason : null,
            'severity' => 'warning',
            'action_url' => $actionUrl,
            'payload' => array_filter([
                'action_url' => $actionUrl,
                'return_no' => $return->return_no,
                'customer_name' => $customerName,
                'total_amount' => (float) $return->total_amount,
                'return_reason' => $returnReason !== '' ? $returnReason : null,
                'proof' => $proof,
            ], fn ($value) => $value !== null),
        ]);
    }
}
