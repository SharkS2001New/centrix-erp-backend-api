<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\Damage;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Inventory\DamageApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class DamageController extends BaseResourceController
{
    use HandlesInventory;

    public function __construct(protected ErpContext $erp) {}

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

        $approval = app(DamageApprovalService::class);
        $gate = $this->erp->gateForUser($user);

        if ($approval->approvalEnabled($gate) && ! $approval->canDirectWriteOff($user)) {
            throw ValidationException::withMessages([
                'authorization' => 'Damage write-offs require manager approval. Submit a request instead.',
            ]);
        }

        return $this->createDamageRecord($data, $user, $request);
    }

    public function requestStore(Request $request)
    {
        $data = $this->validateDamagePayload($request);
        $user = $request->user();
        abort_unless($user, 401);

        $this->access()->assertBranchInOrganization($user, (int) $data['branch_id'], $request);
        $this->access()->assertBranchAccess($user, (int) $data['branch_id']);

        $approval = app(DamageApprovalService::class);
        $gate = $this->erp->gateForUser($user);
        $actionRequest = $approval->requestCreate($user, $data, $gate);

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

    /** @param  array<string, mixed>  $data */
    protected function createDamageRecord(array $data, User $user, Request $request)
    {
        try {
            return DB::transaction(function () use ($data, $user, $request) {
                $damage = Damage::create([
                    ...$data,
                    'reported_by' => $user->id,
                ]);

                $this->postDamageDeduction($damage, $user);

                if ($this->auditable()) {
                    $this->auditLogger()->logModel($user, 'create', $damage, request: $request);
                }

                return response()->json($damage, 201);
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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

        $this->postStockLedger([
            'branch_id' => (int) $damage->branch_id,
            'product_code' => (string) $damage->product_code,
            'stock_location' => (string) $damage->stock_location,
            'transaction_type' => 'DAMAGE',
            'reference_type' => 'damage',
            'reference_id' => $damage->id,
            'quantity_change' => -abs($qty),
            'notes' => $damage->reason ?: 'Stock damage / write-off',
            'created_by' => $user->id,
        ], $allowBelowStock);
    }

    protected function reverseDamageDeduction(Damage $damage, User $user): void
    {
        $qty = (float) $damage->quantity;
        if ($qty <= 0) {
            return;
        }

        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);

        $this->postStockLedger([
            'branch_id' => (int) $damage->branch_id,
            'product_code' => (string) $damage->product_code,
            'stock_location' => (string) $damage->stock_location,
            'transaction_type' => 'ADJUSTMENT',
            'reference_type' => 'damage_reversal',
            'reference_id' => $damage->id,
            'quantity_change' => abs($qty),
            'notes' => 'Damage record reversed or updated',
            'created_by' => $user->id,
        ], $allowBelowStock);
    }
}
