<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Till;
use App\Models\TillFloatSession;
use App\Services\Pos\TillNumbering;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TillController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Till::class;
    }

    protected function scopesByBranch(): bool
    {
        return true;
    }

    protected function findScopedTill(Request $request, string $id): Till
    {
        return $this->findScopedModel($request, $id);
    }

    protected function suggestNextTillLabel(?int $branchId = null): string
    {
        $query = Till::query()->select(['till_number', 'till_name']);
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }
        $label = TillNumbering::nextLabelOrNull($query->get());
        if ($label === null) {
            throw ValidationException::withMessages([
                'till_number' => ['All tills Till01–Till10 are already in use at this branch.'],
            ]);
        }

        return $label;
    }

    protected function tillCodeExists(int $branchId, string $tillCode, ?int $exceptId = null): bool
    {
        $normalized = strtolower(trim($tillCode));
        if ($normalized === '') {
            return false;
        }

        $query = Till::query()
            ->where('branch_id', $branchId)
            ->where(function ($q) use ($normalized) {
                $q->whereRaw('LOWER(TRIM(till_number)) = ?', [$normalized])
                    ->orWhereRaw('LOWER(TRIM(COALESCE(till_name, ""))) = ?', [$normalized]);
            });

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    protected function assertUniqueTillCode(int $branchId, string $tillCode, ?int $exceptId = null): void
    {
        if ($this->tillCodeExists($branchId, $tillCode, $exceptId)) {
            throw new \InvalidArgumentException('A till with this code already exists at the selected branch.');
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function normalizeTillLockFields(array $data, ?Till $existing = null): array
    {
        $lockMode = array_key_exists('lock_mode', $data)
            ? ($data['lock_mode'] !== null && $data['lock_mode'] !== '' ? (string) $data['lock_mode'] : null)
            : ($existing?->lock_mode ?? null);

        if ($lockMode !== null && ! in_array($lockMode, ['user', 'computer'], true)) {
            throw new \InvalidArgumentException('lock_mode must be user, computer, or empty.');
        }

        if ($lockMode === 'user') {
            $cashierId = array_key_exists('cashier_id', $data)
                ? ($data['cashier_id'] !== null && $data['cashier_id'] !== '' ? (int) $data['cashier_id'] : null)
                : ($existing?->cashier_id !== null ? (int) $existing->cashier_id : null);
            if (! $cashierId) {
                throw new \InvalidArgumentException('Select a cashier when locking a till to a user.');
            }
            Till::query()
                ->where('cashier_id', $cashierId)
                ->when($existing?->id, fn ($q) => $q->where('id', '!=', $existing->id))
                ->update(['cashier_id' => null, 'lock_mode' => null]);
            $data['cashier_id'] = $cashierId;
            $data['ip_address'] = null;
            $data['lock_mode'] = 'user';
        } elseif ($lockMode === 'computer') {
            $deviceId = array_key_exists('ip_address', $data)
                ? trim((string) ($data['ip_address'] ?? ''))
                : trim((string) ($existing?->ip_address ?? ''));
            if ($deviceId === '') {
                throw new \InvalidArgumentException('Enter a computer identifier when locking a till to a computer.');
            }
            $data['ip_address'] = $deviceId;
            $data['cashier_id'] = null;
            $data['lock_mode'] = 'computer';
        } else {
            if (array_key_exists('lock_mode', $data)) {
                $data['lock_mode'] = null;
                $data['cashier_id'] = null;
                $data['ip_address'] = null;
            }
        }

        return $data;
    }

    protected function assertCashierLockAllowed(int $cashierId, ?int $exceptTillId = null): void
    {
        $conflict = Till::query()
            ->where('cashier_id', $cashierId)
            ->when($exceptTillId !== null, fn ($q) => $q->where('id', '!=', $exceptTillId))
            ->exists();
        if ($conflict) {
            throw new \InvalidArgumentException('That cashier is already assigned to another till.');
        }

        $activeSession = TillFloatSession::query()
            ->where('cashier_id', $cashierId)
            ->whereIn('status', ['open', 'suspended'])
            ->exists();
        if ($activeSession) {
            throw new \InvalidArgumentException('That cashier has an active session. Close it before reassigning the till.');
        }
    }

    protected function assertTillLockAllowed(Till $till): void
    {
        $hasOpenSession = TillFloatSession::query()
            ->where('till_id', $till->id)
            ->whereIn('status', ['open', 'suspended'])
            ->exists();
        if ($hasOpenSession) {
            throw new \InvalidArgumentException('Close active sessions on this till before changing its lock.');
        }
    }

    protected function assertComputerIdentifierUnique(string $deviceId, ?int $exceptTillId = null): void
    {
        $normalized = strtolower(trim($deviceId));
        if ($normalized === '') {
            return;
        }

        $query = Till::query()
            ->whereRaw('LOWER(TRIM(ip_address)) = ?', [$normalized]);
        if ($exceptTillId !== null) {
            $query->where('id', '!=', $exceptTillId);
        }
        if ($query->exists()) {
            throw new \InvalidArgumentException('That computer identifier is already assigned to another till.');
        }
    }

    public function store(\Illuminate\Http\Request $request)
    {
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $data = $request->validate($rules);

        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : 0;
        if ($branchId <= 0) {
            throw new \InvalidArgumentException('Branch is required.');
        }

        $user = $request->user();
        if ($user) {
            $this->access()->assertBranchAccess($user, $branchId);
            $this->access()->assertBranchInOrganization($user, $branchId, $request);
            $orgId = $this->access()->organizationId($user, $request);
            if ($orgId) {
                $data['organization_id'] = $orgId;
            } else {
                $data['organization_id'] = \App\Support\OrganizationIdResolver::requireForBranch($branchId);
            }
        }

        $label = $this->suggestNextTillLabel($branchId);
        if (empty(trim((string) ($data['till_number'] ?? '')))) {
            $data['till_number'] = $label;
        }
        if (empty(trim((string) ($data['till_name'] ?? '')))) {
            $data['till_name'] = $label;
        }

        $this->assertUniqueTillCode($branchId, (string) $data['till_number']);
        if (! empty(trim((string) ($data['till_name'] ?? '')))) {
            $this->assertUniqueTillCode($branchId, (string) $data['till_name']);
        }

        if (array_key_exists('cashier_id', $data)) {
            $cashierId = $data['cashier_id'] !== null && $data['cashier_id'] !== ''
                ? (int) $data['cashier_id']
                : null;
            $data['cashier_id'] = $cashierId;
        } else {
            $data['cashier_id'] = null;
        }

        $data = $this->normalizeTillLockFields($data);

        if (($data['lock_mode'] ?? null) === 'computer' && ! empty($data['ip_address'])) {
            $this->assertComputerIdentifierUnique((string) $data['ip_address']);
        }

        $model = Till::create($data);

        return response()->json($model, 201);
    }

    public function update(\Illuminate\Http\Request $request, string $id)
    {
        $model = $this->findScopedTill($request, $id);
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $data = $request->validate($rules);

        unset($data['working_amount'], $data['float_breakdown']);

        $branchId = (int) ($data['branch_id'] ?? $model->branch_id);
        if ($branchId <= 0) {
            throw new \InvalidArgumentException('Branch is required.');
        }

        if ($request->user()) {
            $this->access()->assertBranchAccess($request->user(), $branchId);
        }

        if (array_key_exists('cashier_id', $data)) {
            $cashierId = $data['cashier_id'] !== null && $data['cashier_id'] !== ''
                ? (int) $data['cashier_id']
                : null;
            $data['cashier_id'] = $cashierId;
        }

        $changingLock = array_key_exists('lock_mode', $data)
            || array_key_exists('cashier_id', $data)
            || array_key_exists('ip_address', $data);
        if ($changingLock) {
            $this->assertTillLockAllowed($model);
        }

        $data = $this->normalizeTillLockFields($data, $model);

        if (($data['lock_mode'] ?? $model->lock_mode) === 'computer') {
            $deviceId = (string) ($data['ip_address'] ?? $model->ip_address ?? '');
            if ($deviceId !== '') {
                $this->assertComputerIdentifierUnique($deviceId, (int) $model->id);
            }
        }

        if (array_key_exists('till_name', $data) && ! empty(trim((string) $data['till_name']))) {
            $this->assertUniqueTillCode($branchId, (string) $data['till_name'], (int) $model->id);
        }

        if (array_key_exists('till_number', $data) && $data['till_number'] !== null) {
            $this->assertUniqueTillCode($branchId, (string) $data['till_number'], (int) $model->id);
        }

        $model->update($data);

        return response()->json($model);
    }

    public function destroy(Request $request, string $id)
    {
        $model = $this->findScopedTill($request, $id);

        $hasOpenSession = TillFloatSession::query()
            ->where('till_id', $model->id)
            ->whereIn('status', ['open', 'suspended'])
            ->exists();
        if ($hasOpenSession) {
            throw ValidationException::withMessages([
                'till' => ['Close active till sessions before deleting this till.'],
            ]);
        }

        $hasHistory = TillFloatSession::query()
            ->where('till_id', $model->id)
            ->exists();
        if ($hasHistory) {
            throw ValidationException::withMessages([
                'till' => ['This till has session history and cannot be deleted. Close it instead to free assignment.'],
            ]);
        }

        $model->delete();

        return response()->json(null, 204);
    }

    public function close(Request $request, string $id)
    {
        $model = $this->findScopedTill($request, $id);

        $hasOpenSession = TillFloatSession::query()
            ->where('till_id', $model->id)
            ->whereIn('status', ['open', 'suspended'])
            ->exists();
        if ($hasOpenSession) {
            throw ValidationException::withMessages([
                'till' => ['Close active till sessions before closing this till.'],
            ]);
        }

        $model->update([
            'cashier_id' => null,
            'lock_mode' => null,
            'ip_address' => null,
        ]);

        return response()->json($model->fresh());
    }

    public function reopen(Request $request, string $id)
    {
        $model = $this->findScopedTill($request, $id);

        $model->update(['is_active' => true]);

        return response()->json($model->fresh());
    }
}
