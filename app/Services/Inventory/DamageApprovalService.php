<?php

namespace App\Services\Inventory;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\ActionRequest;
use App\Models\Damage;
use App\Models\User;
use App\Services\Accounting\InventoryMovementJournalService;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\NotificationActionUrlBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class DamageApprovalService
{
    use HandlesInventory;

    public function __construct(
        protected UserPermissionService $permissions,
        protected UserAccessService $access,
    ) {}

    public function approvalEnabled(CapabilityGate $gate): bool
    {
        $settings = $gate->moduleSettings('inventory');

        return ! empty($settings['damage_write_off_approval_enabled']);
    }

    public function canDirectWriteOff(User $user): bool
    {
        return $this->permissions->canDirectManageInventory($user);
    }

    public function canApprove(User $user): bool
    {
        return $this->permissions->canApproveInventoryOperations($user);
    }

    /** @param  array<string, mixed>  $data */
    public function requestCreate(User $requester, array $data, CapabilityGate $gate): ActionRequest
    {
        if (! $this->approvalEnabled($gate)) {
            throw ValidationException::withMessages([
                'authorization' => 'Damage write-off approval is not enabled.',
            ]);
        }

        if ($this->canDirectWriteOff($requester)) {
            throw ValidationException::withMessages([
                'authorization' => 'You can record damage write-offs directly.',
            ]);
        }

        $this->access->assertBranchAccess($requester, (int) $data['branch_id']);
        $qty = rtrim(rtrim(number_format((float) $data['quantity'], 4, '.', ''), '0'), '.');
        $requesterName = $requester->full_name ?: $requester->username;
        $actionUrl = NotificationActionUrlBuilder::for('damage', 0);

        return app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'damage_write_off',
            'module' => 'inventory',
            'reference_type' => 'damage',
            'reference_id' => 0,
            'approver_permission' => 'inventory.manage',
            'title' => 'Damage write-off approval required',
            'message' => "{$requesterName} requested write-off of {$qty} {$data['product_code']}.",
            'reason' => $data['reason'] ?? null,
            'severity' => 'danger',
            'action_url' => $actionUrl,
            'allow_duplicate_reference' => true,
            'payload' => [
                'action' => 'create',
                'data' => $data,
                'action_url' => $actionUrl,
            ],
        ]);
    }

    public function approve(ActionRequest $request, User $approver): Damage
    {
        $payload = $request->payload ?? [];
        $data = $payload['data'] ?? [];
        if (! is_array($data)) {
            throw new InvalidArgumentException('Damage approval payload is invalid.');
        }

        $requester = User::query()->findOrFail((int) $request->requested_by);
        $this->access->assertBranchAccess($requester, (int) $data['branch_id']);
        $allowBelowStock = $this->organizationAllowsBelowStock($requester->organization_id);

        return DB::transaction(function () use ($data, $requester, $allowBelowStock, $approver, $request) {
            $damage = Damage::create([
                ...$data,
                'reported_by' => $requester->id,
            ]);

            $ledgerData = $this->withProductUnitCost([
                'branch_id' => (int) $damage->branch_id,
                'product_code' => (string) $damage->product_code,
                'stock_location' => (string) $damage->stock_location,
                'transaction_type' => 'DAMAGE',
                'reference_type' => 'damage',
                'reference_id' => $damage->id,
                'quantity_change' => -abs((float) $damage->quantity),
                'notes' => $damage->reason ?: 'Stock damage / write-off',
                'created_by' => $requester->id,
            ], (int) $requester->organization_id);

            $this->postStockLedger($ledgerData, $allowBelowStock);

            $gate = app(ErpContext::class)->gateForUser($approver);
            $this->postInventoryMovementJournal(
                $approver,
                $gate,
                InventoryMovementJournalService::MOVEMENT_SHRINKAGE,
                (float) $damage->quantity,
                isset($ledgerData['unit_cost']) ? (float) $ledgerData['unit_cost'] : null,
                'DMG-'.$damage->id,
                'Damage write-off #'.$damage->id,
                (int) $damage->branch_id,
                'damage',
                (int) $damage->id,
            );

            app(\App\Services\Audit\OperationalAuditService::class)->logStockMovement($approver, 'damage_approved', [
                'damage_id' => (int) $damage->id,
                'product_code' => (string) $damage->product_code,
                'branch_id' => (int) $damage->branch_id,
                'stock_location' => (string) $damage->stock_location,
                'quantity' => (float) $damage->quantity,
                'action_request_id' => (int) $request->id,
            ]);

            return $damage->fresh();
        });
    }
}
