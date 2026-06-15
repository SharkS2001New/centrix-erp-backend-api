<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Till;
use App\Models\TillFloatSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TillController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Till::class;
    }

    protected function suggestNextTillLabel(?int $branchId = null): string
    {
        $query = Till::query()->select(['till_number', 'till_name']);
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }
        $rows = $query->get();
        $max = 0;
        foreach ($rows as $row) {
            foreach ([$row->till_name, $row->till_number] as $value) {
                if (is_string($value) && preg_match('/^Till(\d+)$/i', trim($value), $m)) {
                    $max = max($max, (int) $m[1]);
                }
            }
        }

        return 'Till'.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
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

    public function store(\Illuminate\Http\Request $request)
    {
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $data = $request->validate($rules);

        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : 0;
        if ($branchId <= 0) {
            throw new \InvalidArgumentException('Branch is required.');
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

        // Float and cashier belong to POS sessions — not stored on the till record.
        $data['cashier_id'] = null;

        $model = Till::create($data);

        return response()->json($model, 201);
    }

    public function update(\Illuminate\Http\Request $request, string $id)
    {
        $model = Till::where($this->routeKeyColumn(), $id)->firstOrFail();
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $data = $request->validate($rules);

        unset($data['working_amount'], $data['float_breakdown']);

        $branchId = (int) ($data['branch_id'] ?? $model->branch_id);
        if ($branchId <= 0) {
            throw new \InvalidArgumentException('Branch is required.');
        }

        if (array_key_exists('cashier_id', $data)) {
            $cashierId = $data['cashier_id'] !== null && $data['cashier_id'] !== ''
                ? (int) $data['cashier_id']
                : null;
            if ($cashierId) {
                $conflict = Till::query()
                    ->where('cashier_id', $cashierId)
                    ->where('id', '!=', $model->id)
                    ->exists();
                if ($conflict) {
                    throw new \InvalidArgumentException('That cashier is already assigned to another till.');
                }
            }
            $data['cashier_id'] = $cashierId;
        }

        if (array_key_exists('till_number', $data) && $data['till_number'] !== null) {
            $this->assertUniqueTillCode($branchId, (string) $data['till_number'], (int) $model->id);
        }
        if (array_key_exists('till_name', $data) && ! empty(trim((string) $data['till_name']))) {
            $this->assertUniqueTillCode($branchId, (string) $data['till_name'], (int) $model->id);
        }

        $model->update($data);

        return response()->json($model);
    }

    public function destroy(Request $request, string $id)
    {
        $till = $this->findScopedModel($request, $id);

        DB::transaction(function () use ($till) {
            $sessionIds = TillFloatSession::query()
                ->where('till_id', $till->id)
                ->pluck('id');

            TillFloatSession::query()
                ->where('till_id', $till->id)
                ->where('status', 'open')
                ->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                ]);

            DB::table('sales')->where('till_id', $till->id)->update(['till_id' => null]);

            if ($sessionIds->isNotEmpty()) {
                DB::table('sales')
                    ->whereIn('float_session_id', $sessionIds)
                    ->update(['float_session_id' => null]);
            }

            TillFloatSession::query()->where('till_id', $till->id)->delete();

            $till->delete();
        });

        return response()->json(null, 204);
    }
}
