<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Employee;
use App\Models\EmployeeOvertime;
use App\Services\Hr\HrPayrollSettingsResolver;
use App\Services\Hr\OvertimeApprovalService;
use App\Services\Notifications\ActionRequestService;
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
                $values = array_values(array_filter(array_map('trim', explode(',', (string) $val))));
                if (count($values) > 1) {
                    $query->whereIn($col, $values);
                } else {
                    $query->where($col, $val);
                }
            }
        }

        if ($request->filled('from_date')) {
            $query->whereDate('work_date', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('work_date', '<=', $request->input('to_date'));
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('notes', 'like', "%{$q}%")
                    ->orWhere('status', 'like', "%{$q}%")
                    ->orWhereHas('employee', function ($emp) use ($q) {
                        $emp->where('full_name', 'like', "%{$q}%")
                            ->orWhere('first_name', 'like', "%{$q}%")
                            ->orWhere('last_name', 'like', "%{$q}%")
                            ->orWhere('employee_code', 'like', "%{$q}%");
                    });
            });
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

        $row = EmployeeOvertime::create($data)->load('employee');
        if ($row->status === 'pending') {
            app(OvertimeApprovalService::class)->notifyOnPending($request->user(), $row);
        }

        return response()->json($row, 201);
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
        $fresh = $row->fresh('employee');
        if ($fresh->status === 'pending') {
            app(OvertimeApprovalService::class)->notifyOnPending($request->user(), $fresh);
        }

        return response()->json($fresh);
    }

    public function destroy(string $id)
    {
        $row = $this->findScoped($id);
        PayrollCycleSettlementService::assertNotPayrollLocked($row->payroll_run_id, 'overtime entry');
        $actor = request()->user();
        if ($row->status === 'pending' && $actor) {
            app(ActionRequestService::class)->cancelAllPendingForDomainReference(
                $actor,
                'employee_overtime',
                (int) $row->id,
                'Overtime entry deleted.',
            );
        }
        $row->delete();

        return response()->json(null, 204);
    }

    public function approve(string $id)
    {
        $row = $this->findScoped($id);
        PayrollCycleSettlementService::assertNotPayrollLocked($row->payroll_run_id, 'overtime entry');
        $approver = request()->user();
        $approved = app(OvertimeApprovalService::class)->approve($row, $approver);
        app(ActionRequestService::class)->markResolvedFromDomain(
            'pending_overtime',
            'employee_overtime',
            (int) $approved->id,
            'approved',
            $approver,
        );

        return response()->json($approved);
    }

    public function deny(string $id)
    {
        $row = $this->findScoped($id);
        PayrollCycleSettlementService::assertNotPayrollLocked($row->payroll_run_id, 'overtime entry');
        $actor = request()->user();
        $overtimeId = (int) $row->id;
        app(OvertimeApprovalService::class)->reject($row, $actor);
        app(ActionRequestService::class)->markResolvedFromDomain(
            'pending_overtime',
            'employee_overtime',
            $overtimeId,
            'rejected',
            $actor,
        );

        return response()->json(null, 204);
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        return $request->validate([
            'employee_id' => $req . 'integer|exists:employees,id',
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'work_date' => $req . 'date',
            'hours' => 'nullable|numeric|min:0',
            'rate_mode' => 'nullable|in:fixed_hourly,from_salary,fixed_amount',
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
        $mode = $data['rate_mode'] ?? 'from_salary';

        if ($mode === 'fixed_amount') {
            $amount = round((float) ($data['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Enter the fixed overtime amount.'],
                ]);
            }
            $data['rate_mode'] = 'fixed_amount';
            $data['hours'] = (float) ($data['hours'] ?? 0);
            $data['hourly_rate'] = null;
            $data['rate_multiplier'] = 1;
            $data['amount'] = $amount;

            return $data;
        }

        if (! empty($data['amount']) && empty($data['hours']) && $mode !== 'fixed_hourly' && $mode !== 'from_salary') {
            return $data;
        }

        $hours = (float) ($data['hours'] ?? 0);
        if ($hours <= 0) {
            throw ValidationException::withMessages([
                'hours' => ['Enter overtime hours.'],
            ]);
        }

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
