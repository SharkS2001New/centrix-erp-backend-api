<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\Branch;
use App\Models\Damage;
use App\Models\User;
use App\Services\Auth\UserAccessService;
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

    protected function scopesByOrganization(): bool
    {
        return false;
    }

    protected function access(): UserAccessService
    {
        return app(UserAccessService::class);
    }

    protected function baseQuery(Request $request)
    {
        $query = Damage::query();
        $user = $request->user();

        if ($user) {
            $orgId = $this->access()->organizationId($user, $request);
            if ($orgId) {
                $query->whereIn('branch_id', function ($sub) use ($orgId) {
                    $sub->select('id')
                        ->from('branches')
                        ->where('organization_id', $orgId);
                });
            }
            $this->access()->scopeBranchIfLimited($query, $user);
        }

        return $query;
    }

    public function store(Request $request)
    {
        $data = $this->validateDamagePayload($request);
        $user = $request->user();
        abort_unless($user, 401);

        $this->assertBranchInOrganization($user, (int) $data['branch_id'], $request);
        $this->access()->assertBranchAccess($user, (int) $data['branch_id']);

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
    }

    public function update(Request $request, string $id)
    {
        $damage = $this->findScopedModel($request, $id);
        $user = $request->user();
        abort_unless($user, 401);

        $data = $this->validateDamagePayload($request, partial: true);
        $oldValues = $damage->getAttributes();

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
    }

    public function destroy(Request $request, string $id)
    {
        $damage = $this->findScopedModel($request, $id);
        $user = $request->user();
        abort_unless($user, 401);

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

    protected function assertBranchInOrganization(User $user, int $branchId, Request $request): void
    {
        $orgId = $this->access()->organizationId($user, $request);
        if (! $orgId) {
            return;
        }

        $exists = Branch::query()
            ->where('id', $branchId)
            ->where('organization_id', $orgId)
            ->exists();

        if (! $exists) {
            abort(403, 'You do not have access to this branch.');
        }
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
