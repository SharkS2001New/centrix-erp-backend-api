<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Employee;
use App\Models\EmployeeOvertime;
use App\Services\Hr\HrPayrollSettingsResolver;
use App\Services\Payroll\OvertimeRateCalculator;
use App\Services\Payroll\PayrollCycleSettlementService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeOvertimeController extends HrOrgResourceController
{
    public function __construct(protected OvertimeRateCalculator $rateCalculator) {}

    protected function modelClass(): string
    {
        return EmployeeOvertime::class;
    }

    public function index(Request $request)
    {
        $query = EmployeeOvertime::query()->with('employee');

        if ($orgId = $request->user()?->organization_id) {
            $query->where('organization_id', $orgId);
        }

        if ($request->user()) {
            app(\App\Services\Auth\UserAccessService::class)
                ->applyBranchListFilter($query, $request->user(), $request);
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if ($col === 'branch_id') {
                continue;
            }
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderByDesc('work_date')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $employee = $this->findOrgEmployee($data['employee_id'], $request);
        if (! $employee->shift_id) {
            throw ValidationException::withMessages([
                'employee_id' => ['Employee must be assigned to a work shift before recording overtime.'],
            ]);
        }
        $data['organization_id'] = $data['organization_id'] ?? $employee->organization_id;
        $data['branch_id'] = $data['branch_id'] ?? $employee->branch_id;
        $data = $this->computeAmount($data, $employee);

        return response()->json(EmployeeOvertime::create($data)->load('employee'), 201);
    }

    public function update(Request $request, string $id)
    {
        $row = $this->findScoped($id);
        PayrollCycleSettlementService::assertNotPayrollLocked($row->payroll_run_id, 'overtime entry');
        $data = $this->validated($request, updating: true);
        $employee = $this->findOrgEmployee($data['employee_id'] ?? $row->employee_id, $request);
        if ($employee && ! $employee->shift_id) {
            throw ValidationException::withMessages([
                'employee_id' => ['Employee must be assigned to a work shift before recording overtime.'],
            ]);
        }
        $data = $this->computeAmount(array_merge($row->toArray(), $data), $employee);

        $row->update($data);

        return response()->json($row->fresh('employee'));
    }

    public function destroy(string $id)
    {
        $row = $this->findScoped($id);
        PayrollCycleSettlementService::assertNotPayrollLocked($row->payroll_run_id, 'overtime entry');
        $row->delete();

        return response()->json(null, 204);
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        return $request->validate([
            'employee_id' => $req . 'integer|exists:employees,id',
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'work_date' => $req . 'date',
            'hours' => $req . 'numeric|min:0',
            'rate_mode' => 'nullable|in:fixed_hourly,from_salary',
            'hourly_rate' => 'nullable|numeric|min:0',
            'rate_multiplier' => 'nullable|numeric|min:1',
            'amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:pending,approved,paid,rejected',
            'pay_period_id' => 'nullable|integer|exists:pay_periods,id',
            'notes' => 'nullable|string|max:500',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    protected function computeAmount(array $data, ?Employee $employee): array
    {
        if (! empty($data['amount']) && empty($data['hours'])) {
            return $data;
        }

        $hours = (float) ($data['hours'] ?? 0);
        $mode = $data['rate_mode'] ?? 'from_salary';
        $orgId = (int) ($employee?->organization_id ?? $data['organization_id'] ?? 0);
        $hr = HrPayrollSettingsResolver::forOrganizationId($orgId ?: null);
        $mult = (float) ($data['rate_multiplier'] ?? $hr['overtime_rate_multiplier'] ?? 1.5);
        if ($mult < 1) {
            $mult = 1;
        }

        if ($mode === 'fixed_hourly') {
            $rate = (float) ($data['hourly_rate'] ?? 0);
            if ($rate <= 0) {
                throw ValidationException::withMessages([
                    'hourly_rate' => ['Enter the fixed amount per hour for this overtime entry.'],
                ]);
            }
        } else {
            if (! $employee) {
                throw ValidationException::withMessages([
                    'employee_id' => ['Employee is required to calculate overtime from salary.'],
                ]);
            }
            $rate = $this->rateCalculator->hourlyFromSalary(
                $employee,
                isset($data['work_date']) ? (string) $data['work_date'] : null,
            );
            $data['hourly_rate'] = $rate;
            $data['rate_mode'] = 'from_salary';
        }

        $data['amount'] = round($hours * $rate * $mult, 2);

        return $data;
    }
}
