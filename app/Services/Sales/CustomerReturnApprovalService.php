<?php

namespace App\Services\Sales;

use App\Models\CustomerReturn;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\NotificationActionUrlBuilder;

class CustomerReturnApprovalService
{
    public function __construct(
        protected UserPermissionService $permissions,
    ) {}

    public function canApprove(User $user): bool
    {
        return (bool) $user->is_admin
            || $this->permissions->hasPermission($user, 'sales.manage');
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

        app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'customer_return',
            'module' => 'sales',
            'reference_type' => 'customer_return',
            'reference_id' => (int) $return->id,
            'approver_permission' => 'sales.manage',
            'title' => 'Customer return approval required',
            'message' => "{$requesterName} submitted return {$return->return_no} for {$customerName} (KES ".number_format((float) $return->total_amount, 2).').',
            'reason' => $return->reason,
            'severity' => 'warning',
            'action_url' => $actionUrl,
            'payload' => [
                'action_url' => $actionUrl,
                'return_no' => $return->return_no,
                'customer_name' => $customerName,
                'total_amount' => (float) $return->total_amount,
            ],
        ]);
    }
}
