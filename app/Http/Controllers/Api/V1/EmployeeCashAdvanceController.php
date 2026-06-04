<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Employee;
use App\Models\EmployeeCashAdvance;
use Illuminate\Http\Request;

class EmployeeCashAdvanceController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return EmployeeCashAdvance::class;
    }

    public function index(Request $request)
    {
        $query = EmployeeCashAdvance::query()->with('employee');

        if ($orgId = $request->user()?->organization_id) {
            $query->where('organization_id', $orgId);
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderByDesc('advance_date')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $employee = Employee::findOrFail($data['employee_id']);
        $data['organization_id'] = $data['organization_id'] ?? $employee->organization_id;
        $data['balance'] = $data['balance'] ?? $data['amount'];
        $data['repayment_mode'] = $data['repayment_mode'] ?? 'full_next_cycle';
        $data = $this->normalizeRepayment($data);

        return response()->json(EmployeeCashAdvance::create($data)->load('employee'), 201);
    }

    public function update(Request $request, string $id)
    {
        $row = $this->findScoped($id);
        $data = $this->normalizeRepayment($this->validated($request, updating: true));
        $row->update($data);

        return response()->json($row->fresh('employee'));
    }

    /** @param array<string, mixed> $data */
    protected function normalizeRepayment(array $data): array
    {
        $mode = trim((string) ($data['repayment_mode'] ?? 'full_next_cycle'));
        if (! in_array($mode, ['full_next_cycle', 'fixed_per_cycle'], true)) {
            $mode = 'full_next_cycle';
        }
        $data['repayment_mode'] = $mode;

        if ($mode === 'full_next_cycle') {
            $data['repayment_amount'] = null;
        } elseif (empty($data['repayment_amount']) || (float) $data['repayment_amount'] <= 0) {
            $data['repayment_mode'] = 'full_next_cycle';
            $data['repayment_amount'] = null;
        }

        return $data;
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        return $request->validate([
            'employee_id' => $req . 'integer|exists:employees,id',
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'advance_date' => $req . 'date',
            'amount' => $req . 'numeric|min:0',
            'balance' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:open,repaid,cancelled',
            'repayment_mode' => 'nullable|in:full_next_cycle,fixed_per_cycle',
            'repayment_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);
    }
}
