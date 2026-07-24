<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Employee;
use App\Models\EmployeeDeduction;
use App\Models\PayrollDeductionType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollDeductionTypeController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return PayrollDeductionType::class;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $employeeIds = array_values(array_unique(array_map('intval', $data['employee_ids'] ?? [])));
        unset($data['employee_ids']);

        $user = $request->user();
        if ($user && $this->modelHasColumn('organization_id') && empty($data['organization_id'])) {
            $data['organization_id'] = $user->organization_id;
        }
        if ($user && $this->modelHasColumn('branch_id')) {
            $this->applyBranchScopeToWriteData($user, $data, $request);
        }

        if ($employeeIds !== []) {
            $data['applies_to_all'] = false;
        }

        $assigned = 0;
        $model = DB::transaction(function () use ($request, $data, $employeeIds, &$assigned) {
            $type = PayrollDeductionType::create($data);
            $assigned = $this->assignToEmployees($request, $type, $employeeIds);

            return $type;
        });

        return response()->json(array_merge($model->toArray(), [
            'assigned_employee_count' => $assigned,
        ]), 201);
    }

    public function update(Request $request, string $id)
    {
        $model = $this->findScoped($id);
        $data = $this->validated($request, updating: true);
        $employeeIds = array_key_exists('employee_ids', $data)
            ? array_values(array_unique(array_map('intval', $data['employee_ids'] ?? [])))
            : null;
        unset($data['employee_ids']);

        $user = $request->user();
        if ($user && $this->modelHasColumn('branch_id')) {
            $this->applyBranchScopeToWriteData($user, $data, $request);
        }

        if (is_array($employeeIds) && $employeeIds !== []) {
            $data['applies_to_all'] = false;
        }

        $assigned = 0;
        DB::transaction(function () use ($request, $model, $data, $employeeIds, &$assigned) {
            $model->update($data);
            if (is_array($employeeIds)) {
                $assigned = $this->assignToEmployees($request, $model->fresh(), $employeeIds);
            }
        });

        return response()->json(array_merge($model->fresh()->toArray(), [
            'assigned_employee_count' => $assigned,
        ]));
    }

    /**
     * Create employee_deductions for the given employees (skip existing type links).
     *
     * @param  list<int>  $employeeIds
     */
    protected function assignToEmployees(Request $request, PayrollDeductionType $type, array $employeeIds): int
    {
        if ($employeeIds === []) {
            return 0;
        }

        $orgId = (int) ($type->organization_id ?: $request->user()?->organization_id ?? 0);
        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->when($orgId > 0, fn ($q) => $q->where('organization_id', $orgId))
            ->get();

        if ($employees->count() !== count($employeeIds)) {
            abort(422, 'One or more selected employees were not found in your organization.');
        }

        $existing = EmployeeDeduction::query()
            ->where('deduction_type_id', $type->id)
            ->whereIn('employee_id', $employeeIds)
            ->pluck('employee_id')
            ->all();
        $existingSet = array_fill_keys(array_map('intval', $existing), true);

        $created = 0;
        foreach ($employees as $employee) {
            if (isset($existingSet[(int) $employee->id])) {
                continue;
            }

            EmployeeDeduction::create([
                'employee_id' => $employee->id,
                'branch_id' => $employee->branch_id,
                'deduction_type_id' => $type->id,
                'name' => $type->name,
                'calc_type' => $type->calc_type ?: 'fixed',
                'amount' => $type->calc_type === 'percentage' ? 0 : (float) $type->default_amount,
                'percentage' => $type->calc_type === 'percentage' ? (float) $type->default_percentage : null,
                'is_active' => (bool) $type->is_active,
            ]);
            $created++;
        }

        return $created;
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        return $request->validate([
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'deduction_code' => $req . 'string|max:45',
            'name' => $req . 'string|max:200',
            'calc_type' => 'nullable|in:fixed,percentage',
            'default_amount' => 'nullable|numeric|min:0',
            'default_percentage' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
            'applies_to_all' => 'nullable|boolean',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'integer|exists:employees,id',
        ]);
    }
}
