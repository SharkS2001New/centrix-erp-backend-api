<?php

namespace App\Services\Purchasing;

use App\Models\ActionRequest;
use App\Models\LpoMst;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\LpoModuleService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\NotificationActionUrlBuilder;
use Illuminate\Validation\ValidationException;

class LpoApprovalService
{
    public function __construct(
        protected UserPermissionService $permissions,
        protected LpoModuleService $lpoModule,
    ) {}

    public function canApprove(User $user): bool
    {
        return $this->permissions->canApproveLpoRequests($user);
    }

    public function assertCanApprove(User $user): void
    {
        if (! $this->canApprove($user)) {
            throw ValidationException::withMessages([
                'action' => ['You do not have permission to approve LPOs.'],
            ]);
        }
    }

    public function requestApproval(User $requester, LpoMst $lpo): ActionRequest
    {
        if ((int) $lpo->lpo_status_code !== LpoWorkflowService::STATUS_AWAITING_APPROVAL) {
            throw ValidationException::withMessages([
                'status' => 'Only LPOs awaiting approval can be submitted for approval.',
            ]);
        }

        $lpo->loadMissing('supplier');
        $poNumber = $this->lpoModule->formatPoNumber((int) $lpo->lpo_seq);
        $supplier = $lpo->supplier?->supplier_name ?? 'supplier';
        $requesterName = $requester->full_name ?: $requester->username;
        $actionUrl = NotificationActionUrlBuilder::for('lpo', (int) $lpo->lpo_no);

        return app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'lpo_approval',
            'module' => 'purchasing',
            'reference_type' => 'lpo_mst',
            'reference_id' => (int) $lpo->lpo_no,
            'approver_permission' => 'purchasing.lpo.approve',
            'title' => 'LPO approval required',
            'message' => "{$requesterName} submitted {$poNumber} for {$supplier} for approval.",
            'severity' => 'warning',
            'action_url' => $actionUrl,
            'payload' => [
                'po_number' => $poNumber,
                'supplier_name' => $supplier,
                'net_amount' => round((float) $lpo->net_amount, 2),
                'action_url' => $actionUrl,
            ],
        ]);
    }

    public function approve(ActionRequest $request, User $approver): LpoMst
    {
        $lpo = LpoMst::query()
            ->where('organization_id', $request->organization_id)
            ->where('lpo_no', (int) $request->reference_id)
            ->firstOrFail();

        $organization = Organization::query()->findOrFail((int) $request->organization_id);

        return app(LpoWorkflowService::class)->approveFromActionRequest($lpo, $approver, $organization);
    }

    public function reject(ActionRequest $request, User $approver, ?string $reason = null): LpoMst
    {
        $lpo = LpoMst::query()
            ->where('organization_id', $request->organization_id)
            ->where('lpo_no', (int) $request->reference_id)
            ->firstOrFail();

        $lpo->update([
            'lpo_status_code' => LpoWorkflowService::STATUS_AWAITING_CHECK,
        ]);

        return $lpo->fresh();
    }
}
