<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Employee;
use App\Models\EmployeeAllowance;

class EmployeeAllowanceController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return EmployeeAllowance::class;
    }

    public function index(\Illuminate\Http\Request $request)
    {
        $query = EmployeeAllowance::query()->with('employee');

        if ($orgId = $request->user()?->organization_id) {
            $query->where('organization_id', $orgId);
        }

        if ($empId = $request->input('employee_id')) {
            $query->where('employee_id', $empId);
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(\Illuminate\Http\Request $request)
    {
        $data = $this->validated($request);
        $employee = Employee::findOrFail($data['employee_id']);
        $data['organization_id'] = $data['organization_id'] ?? $employee->organization_id;

        return response()->json(
            EmployeeAllowance::create($data)->load('employee'),
            201,
        );
    }

    protected function validated(\Illuminate\Http\Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        return $request->validate([
            'employee_id' => $req . 'integer|exists:employees,id',
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'name' => $req . 'string|max:120',
            'amount' => $req . 'numeric|min:0',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
        ]);
    }
}
