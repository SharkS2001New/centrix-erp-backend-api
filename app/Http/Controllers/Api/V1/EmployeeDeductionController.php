<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDeduction;
use Illuminate\Http\Request;

class EmployeeDeductionController extends Controller
{
    public function index(Request $request)
    {
        $query = EmployeeDeduction::query()->with(['employee', 'deductionType']);

        if ($employeeId = $request->input('filter')['employee_id'] ?? $request->input('employee_id')) {
            $query->where('employee_id', (int) $employeeId);
        }

        if ($orgId = $request->user()?->organization_id) {
            $query->whereHas('employee', fn ($q) => $q->where('organization_id', $orgId));
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        Employee::findOrFail($data['employee_id']);

        return response()->json(
            EmployeeDeduction::create($data)->load(['employee', 'deductionType']),
            201,
        );
    }

    public function show(string $id)
    {
        return response()->json(
            EmployeeDeduction::with(['employee', 'deductionType'])->findOrFail((int) $id),
        );
    }

    public function update(Request $request, string $id)
    {
        $row = EmployeeDeduction::findOrFail((int) $id);
        $row->update($this->validated($request, updating: true));

        return response()->json($row->fresh(['employee', 'deductionType']));
    }

    public function destroy(string $id)
    {
        EmployeeDeduction::findOrFail((int) $id)->delete();

        return response()->json(null, 204);
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        return $request->validate([
            'employee_id' => $req . 'integer|exists:employees,id',
            'deduction_type_id' => 'nullable|integer|exists:payroll_deduction_types,id',
            'name' => $req . 'string|max:200',
            'calc_type' => 'nullable|in:fixed,percentage',
            'amount' => 'nullable|numeric|min:0',
            'percentage' => 'nullable|numeric|min:0|max:100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
        ]);
    }
}
