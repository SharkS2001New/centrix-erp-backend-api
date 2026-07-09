<?php

namespace App\Services\Inventory;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\ActionRequest;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\Accounting\InventoryMovementJournalService;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Validation\ValidationException;

class StockAdjustmentApprovalService
{
    use HandlesInventory;

    public function __construct(
        protected UserPermissionService $permissions,
        protected UserAccessService $access,
    ) {}

    public function approvalEnabled(CapabilityGate $gate): bool
    {
        $settings = $gate->moduleSettings('inventory');

        return ! empty($settings['stock_adjustment_approval_enabled']);
    }

    public function canDirectAdjust(User $user): bool
    {
        return $this->permissions->canDirectManageInventory($user);
    }

    public function canApprove(User $user): bool
    {
        return $this->permissions->canApproveInventoryOperations($user);
    }

    /** @param  array<string, mixed>  $data */
    public function requestAdjustment(User $user, array $data, CapabilityGate $gate): ActionRequest
    {
        if (! $this->approvalEnabled($gate)) {
            throw ValidationException::withMessages([
                'authorization' => 'Stock adjustment approval is not enabled.',
            ]);
        }

        if ($this->canDirectAdjust($user)) {
            throw ValidationException::withMessages([
                'authorization' => 'You can post stock adjustments directly.',
            ]);
        }

        $this->access->assertBranchAccess($user, (int) $data['branch_id']);

        $product = Product::query()->where('product_code', $data['product_code'])->first();
        $productName = $product?->product_name ?? $data['product_code'];
        $requesterName = $user->full_name ?: $user->username;
        $qty = (float) $data['quantity_change'];
        $qtyLabel = ($qty > 0 ? '+' : '').rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');

        return app(ActionRequestService::class)->requestApproval($user, [
            'type' => 'stock_adjustment',
            'module' => 'inventory',
            'reference_type' => 'stock_adjustment_request',
            'reference_id' => 0,
            'approver_permission' => 'inventory.manage',
            'title' => 'Stock adjustment approval required',
            'message' => "{$requesterName} requested {$qtyLabel} adjustment for {$productName}.",
            'reason' => $data['notes'] ?? null,
            'severity' => 'warning',
            'action_url' => '/inventory/adjustments',
            'allow_duplicate_reference' => true,
            'payload' => [
                'branch_id' => (int) $data['branch_id'],
                'product_code' => (string) $data['product_code'],
                'product_name' => $productName,
                'stock_location' => (string) $data['stock_location'],
                'quantity_change' => $qty,
                'notes' => $data['notes'] ?? null,
                'action_url' => '/inventory/adjustments',
            ],
        ]);
    }

    public function applyFromActionRequest(ActionRequest $request, User $approver): InventoryTransaction
    {
        $payload = $request->payload ?? [];
        $this->access->assertBranchAccess($approver, (int) ($payload['branch_id'] ?? 0));

        $allowBelowStock = $this->organizationAllowsBelowStock($approver->organization_id);

        $ledgerData = $this->withProductUnitCost([
            'branch_id' => (int) $payload['branch_id'],
            'product_code' => (string) $payload['product_code'],
            'stock_location' => (string) $payload['stock_location'],
            'quantity_change' => (float) $payload['quantity_change'],
            'notes' => $payload['notes'] ?? $request->reason,
            'transaction_type' => 'ADJUSTMENT',
            'reference_type' => 'adjustment',
            'created_by' => (int) $request->requested_by,
        ], (int) $approver->organization_id);

        $txn = $this->postStockLedger($ledgerData, $allowBelowStock);

        $qtyChange = (float) $payload['quantity_change'];
        $movementType = $qtyChange >= 0
            ? InventoryMovementJournalService::MOVEMENT_INCREASE
            : InventoryMovementJournalService::MOVEMENT_SHRINKAGE;
        $gate = app(ErpContext::class)->gateForUser($approver);
        $this->postInventoryMovementJournal(
            $approver,
            $gate,
            $movementType,
            abs($qtyChange),
            isset($ledgerData['unit_cost']) ? (float) $ledgerData['unit_cost'] : null,
            'ADJ-'.$txn->id,
            'Stock adjustment #'.$txn->id,
            (int) $payload['branch_id'],
            'adjustment',
            (int) $txn->id,
            (string) $payload['product_code'],
        );

        app(\App\Services\Audit\OperationalAuditService::class)->logStockMovement($approver, 'adjustment_approved', [
            'product_code' => (string) $payload['product_code'],
            'branch_id' => (int) $payload['branch_id'],
            'stock_location' => (string) $payload['stock_location'],
            'quantity_change' => (float) $payload['quantity_change'],
            'action_request_id' => (int) $request->id,
            'transaction_id' => (int) $txn->id,
        ]);

        return $txn;
    }
}
