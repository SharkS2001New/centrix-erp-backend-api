<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\Damage;
use App\Models\User;
use App\Services\Accounting\InventoryMovementJournalService;
use App\Services\Audit\OperationalAuditService;
use App\Services\Erp\ErpContext;
use App\Services\Inventory\DamageApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DamageController extends BaseResourceController
{
    use HandlesInventory;

    protected function modelClass(): string
    {
        return Damage::class;
    }

    protected function baseQuery(Request $request)
    {
        return parent::baseQuery($request)
            ->with(['product:product_code,product_name,unit_id']);
    }

    public function store(Request $request)
    {
        $data = $this->validateDamagePayload($request);
        $user = $request->user();
        abort_unless($user, 401);

        $this->access()->assertBranchInOrganization($user, (int) $data['branch_id'], $request);
        $this->access()->assertBranchAccess($user, (int) $data['branch_id']);

        $gate = app(ErpContext::class)->gateForUser($user);
        $approval = app(DamageApprovalService::class);

        if ($approval->approvalEnabled($gate) && ! $approval->canDirectWriteOff($user)) {
            $actionRequest = $approval->requestCreate($user, $data, $gate);

            return response()->json([
                'message' => 'Damage write-off submitted for admin approval.',
                'pending_approval' => true,
                'action_request_id' => (int) $actionRequest->id,
            ], 202);
        }

        try {
            return DB::transaction(function () use ($data, $user, $request) {
                $damage = Damage::create([
                    ...$data,
                    'reported_by' => $user->id,
                ]);

                $this->postDamageDeduction($damage, $user);

                app(OperationalAuditService::class)->logStockMovement($user, 'damage', [
                    'damage_id' => (int) $damage->id,
                    'product_code' => (string) $damage->product_code,
                    'branch_id' => (int) $damage->branch_id,
                    'stock_location' => (string) $damage->stock_location,
                    'quantity' => (float) $damage->quantity,
                ]);

                if ($this->auditable()) {
                    $this->auditLogger()->logModel($user, 'create', $damage, request: $request);
                }

                return response()->json($damage, 201);
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function requestStore(Request $request)
    {
        $data = $this->validateDamagePayload($request);
        $user = $request->user();
        abort_unless($user, 401);

        $this->access()->assertBranchInOrganization($user, (int) $data['branch_id'], $request);
        $this->access()->assertBranchAccess($user, (int) $data['branch_id']);

        $gate = app(ErpContext::class)->gateForUser($user);
        $actionRequest = app(DamageApprovalService::class)->requestCreate($user, $data, $gate);

        return response()->json([
            'message' => 'Damage write-off submitted for admin approval.',
            'pending_approval' => true,
            'action_request_id' => (int) $actionRequest->id,
        ], 202);
    }

    public function update(Request $request, string $id)
    {
        $damage = $this->findScopedModel($request, $id);
        $user = $request->user();
        abort_unless($user, 401);

        $data = $this->validateDamagePayload($request, partial: true);
        $oldValues = $damage->getAttributes();

        try {
            return DB::transaction(function () use ($damage, $data, $user, $request, $oldValues) {
                $this->reverseDamageDeduction($damage, $user);

                $damage->update($data);
                $damage->refresh();

                $this->postDamageDeduction($damage, $user);

                if ($this->auditable()) {
                    $this->auditLogger()->logModel(
                        $user,
                        'update',
                        $damage,
                        $oldValues,
                        $damage->getAttributes(),
                        $request,
                    );
                }

                return response()->json($damage);
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, string $id)
    {
        $damage = $this->findScopedModel($request, $id);
        $user = $request->user();
        abort_unless($user, 401);

        try {
            return DB::transaction(function () use ($damage, $user, $request) {
                if ($this->auditable()) {
                    $this->auditLogger()->logModel(
                        $user,
                        'delete',
                        $damage,
                        $damage->getAttributes(),
                        null,
                        $request,
                    );
                }

                $this->reverseDamageDeduction($damage, $user);
                $damage->delete();

                return response()->json(null, 204);
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** @return array<string, mixed> */
    protected function validateDamagePayload(Request $request, bool $partial = false): array
    {
        $rules = [
            'product_code' => ($partial ? 'sometimes|' : '').'required|string|exists:products,product_code',
            'branch_id' => ($partial ? 'sometimes|' : '').'required|integer|exists:branches,id',
            'quantity' => ($partial ? 'sometimes|' : '').'required|numeric|min:0.001',
            'package_type' => 'nullable|string',
            'uom_label' => 'nullable|string',
            'stock_location' => ($partial ? 'sometimes|' : '').'required|in:shop,store',
            'reason' => 'nullable|string',
        ];

        $data = $request->validate($rules);

        if (isset($data['package_type'])) {
            $data['package_type'] = $this->normalizePackageType((string) $data['package_type']);
        }

        return $data;
    }

    protected function normalizePackageType(string $packageType): string
    {
        return match ($packageType) {
            'full', 'full_package' => 'full_package',
            'pieces' => 'pieces',
            default => 'partial',
        };
    }

    protected function postDamageDeduction(Damage $damage, User $user): void
    {
        $qty = (float) $damage->quantity;
        if ($qty <= 0) {
            throw new InvalidArgumentException('Damage quantity must be positive.');
        }

        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);
        $ledgerData = $this->withProductUnitCost([
            'branch_id' => (int) $damage->branch_id,
            'product_code' => (string) $damage->product_code,
            'stock_location' => (string) $damage->stock_location,
            'transaction_type' => 'DAMAGE',
            'reference_type' => 'damage',
            'reference_id' => $damage->id,
            'quantity_change' => -abs($qty),
            'notes' => $damage->reason ?: 'Stock damage / write-off',
            'created_by' => $user->id,
        ], (int) $user->organization_id);

        $this->postStockLedger($ledgerData, $allowBelowStock);

        $gate = app(ErpContext::class)->gateForUser($user);
        $this->postInventoryMovementJournal(
            $user,
            $gate,
            InventoryMovementJournalService::MOVEMENT_SHRINKAGE,
            $qty,
            isset($ledgerData['unit_cost']) ? (float) $ledgerData['unit_cost'] : null,
            'DMG-'.$damage->id,
            'Damage write-off #'.$damage->id,
            (int) $damage->branch_id,
            'damage',
            (int) $damage->id,
            (string) $damage->product_code,
        );
    }

    protected function reverseDamageDeduction(Damage $damage, User $user): void
    {
        $qty = (float) $damage->quantity;
        if ($qty <= 0) {
            return;
        }

        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);
        $unitCost = \App\Models\InventoryTransaction::query()
            ->where('reference_type', 'damage')
            ->where('reference_id', $damage->id)
            ->where('quantity_change', '<', 0)
            ->orderByDesc('id')
            ->value('unit_cost');
        $unitCost = ($unitCost !== null && (float) $unitCost > 0)
            ? (float) $unitCost
            : $this->productUnitCost((int) $user->organization_id, (string) $damage->product_code);

        $this->postStockLedger([
            'branch_id' => (int) $damage->branch_id,
            'product_code' => (string) $damage->product_code,
            'stock_location' => (string) $damage->stock_location,
            'transaction_type' => 'ADJUSTMENT',
            'reference_type' => 'damage_reversal',
            'reference_id' => $damage->id,
            'quantity_change' => abs($qty),
            'unit_cost' => $unitCost > 0 ? $unitCost : null,
            'notes' => 'Damage record reversed or updated',
            'created_by' => $user->id,
        ], $allowBelowStock);

        $gate = app(ErpContext::class)->gateForUser($user);
        $this->postInventoryMovementJournal(
            $user,
            $gate,
            InventoryMovementJournalService::MOVEMENT_INCREASE,
            $qty,
            $unitCost > 0 ? $unitCost : null,
            'DMG-REV-'.$damage->id.'-'.now()->timestamp,
            'Damage reversal #'.$damage->id,
            (int) $damage->branch_id,
            'damage_reversal',
            (int) $damage->id,
            (string) $damage->product_code,
        );
    }
}
