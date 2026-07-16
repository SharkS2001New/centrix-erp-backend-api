<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Employee;
use App\Models\EmployeeLeaveDay;
use App\Models\User;
use App\Services\Attendance\LeaveBalanceService;
use App\Services\Attendance\LeaveRequestCalculator;
use App\Services\Notifications\ActionRequestService;
use App\Services\Payroll\PayrollCycleSettlementService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeLeaveDayController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return EmployeeLeaveDay::class;
    }

    public function index(Request $request)
    {
        $query = EmployeeLeaveDay::query()->with('employee');

        if ($orgId = $request->user()?->organization_id) {
            $query->where('organization_id', $orgId);
        }

        if ($request->user()) {
            app(\App\Services\Auth\UserAccessService::class)
                ->applyBranchListFilter($query, $request->user(), $request);
        }

        if ($empId = $request->input('employee_id')) {
            $query->where('employee_id', $empId);
        }

        if ($kind = $request->input('assignment_kind')) {
            $query->where('assignment_kind', $kind);
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $paginator = $query->orderByDesc('start_date')->paginate($perPage);
        $viewer = $request->user();
        $paginator->getCollection()->transform(fn (EmployeeLeaveDay $leave) => $this->leaveWithMeta($leave, $viewer));

        return response()->json($paginator);
    }

    public function show(string $id)
    {
        $leave = $this->findScoped($id)->load('employee');

        return response()->json($this->leaveWithMeta($leave, request()->user()));
    }

    protected function leaveWithMeta(EmployeeLeaveDay $leave, ?User $viewer): EmployeeLeaveDay
    {
        if ($viewer && $leave->approval_status === 'pending') {
            $leave->setAttribute(
                'action_request',
                app(ActionRequestService::class)->presentPendingFor(
                    $viewer,
                    'leave_request',
                    'employee_leave_day',
                    (int) $leave->id,
                ),
            );
        }

        return $leave;
    }

    /** GET /employees/{employee}/leave-balances */
    public function balances(string $employeeId)
    {
        $employee = $this->findOrgEmployee($employeeId);

        $service = app(LeaveBalanceService::class);
        $balance = \App\Models\EmployeeLeaveBalance::forEmployee($employee);
        $exceptLeaveId = request()->integer('except_leave_id') ?: null;

        return response()->json([
            'employee_id' => $employee->id,
            'months_of_service' => $service->monthsOfService($employee),
            'system' => [
                'annual_entitled' => round($service->systemAnnualEntitled($employee), 2),
                'sick_entitled' => round($service->systemSickEntitled($employee), 2),
            ],
            'adjustments' => [
                'annual' => (float) $balance->annual_adjustment,
                'sick' => (float) $balance->sick_adjustment,
            ],
            'off_days_allocated' => (float) $balance->off_days_allocated,
            'notes' => $balance->notes,
            'balances' => $service->summary($employee, $exceptLeaveId),
        ]);
    }

    /** GET /employee-leave-days/calculate */
    public function calculate(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'duration_type' => 'required|in:full_day,half_day',
            'half_day_period' => 'nullable|in:morning,afternoon',
            'deduct_from' => 'nullable|in:annual,sick,off_days,unpaid',
            'assignment_kind' => 'nullable|in:leave,off_day',
            'except_leave_id' => 'nullable|integer|exists:employee_leave_days,id',
        ]);

        $employee = $this->findOrgEmployee($data['employee_id']);
        $deductFrom = $this->resolveDeductFrom($data);
        $exceptLeaveId = isset($data['except_leave_id']) ? (int) $data['except_leave_id'] : null;

        try {
            $result = app(LeaveRequestCalculator::class)->calculate(
                $employee,
                $data['start_date'],
                $data['end_date'],
                $data['duration_type'],
                $data['half_day_period'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['dates' => [$e->getMessage()]]);
        }

        $balanceService = app(LeaveBalanceService::class);
        $balances = $balanceService->summary($employee, $exceptLeaveId);
        $days = (float) $result['total_days'];
        $available = $deductFrom === 'unpaid'
            ? null
            : $balanceService->available($employee, $deductFrom, $exceptLeaveId);
        $canAssign = $deductFrom === 'unpaid' || ($available !== null && $available >= $days);

        return response()->json(array_merge($result, [
            'deduct_from' => $deductFrom,
            'balances' => $balances,
            'available_for_pool' => $available,
            'available_after_assign' => $available !== null ? max(0, round($available - $days, 2)) : null,
            'can_assign' => $canAssign,
            'balance_message' => $canAssign
                ? null
                : $this->insufficientBalanceMessage($deductFrom, $available ?? 0, $days),
        ]));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $employee = $this->findOrgEmployee($data['employee_id']);
        $data['organization_id'] = $employee->organization_id;
        $data['branch_id'] = $employee->branch_id;
        $data = $this->applyTotals($employee, $data);
        $data = $this->applyDeductionMeta($data);
        $this->assertNoOverlap($employee->id, $data['start_date'], $data['end_date'], null);
        $data['approval_status'] = $data['approval_status'] ?? 'pending';
        if ($data['approval_status'] === 'approved') {
            app(LeaveBalanceService::class)->assertCanDeduct(
                $employee,
                $data['deduct_from'],
                (float) $data['days_deducted'],
            );
        }

        $row = EmployeeLeaveDay::create($data)->load('employee');

        if ($row->approval_status === 'pending') {
            app(\App\Services\Hr\LeaveApprovalService::class)->notifyOnCreate($request->user(), $row);
        }

        return response()->json($this->leaveWithMeta($row, $request->user()), 201);
    }

    public function approve(string $id)
    {
        $row = $this->findScoped($id);
        $approved = app(\App\Services\Hr\LeaveApprovalService::class)->approve($row, request()->user());

        app(\App\Services\Notifications\ActionRequestService::class)->markResolvedFromDomain(
            'leave_request',
            'employee_leave_day',
            (int) $approved->id,
            'approved',
            request()->user(),
        );

        return response()->json($approved);
    }

    /** POST /employee-leave-days/{id}/reject */
    public function reject(string $id)
    {
        $row = $this->findScoped($id);
        $rejected = app(\App\Services\Hr\LeaveApprovalService::class)->reject($row, request()->user());

        app(\App\Services\Notifications\ActionRequestService::class)->markResolvedFromDomain(
            'leave_request',
            'employee_leave_day',
            (int) $rejected->id,
            'rejected',
            request()->user(),
        );

        return response()->json($rejected);
    }

    public function update(Request $request, string $id)
    {
        $row = EmployeeLeaveDay::findOrFail($id);
        PayrollCycleSettlementService::assertNotPayrollLocked($row->payroll_run_id, 'leave assignment');
        $data = $this->validated($request, updating: true);
        $employee = $this->findOrgEmployee($data['employee_id'] ?? $row->employee_id);
        $merged = array_merge($row->only([
            'employee_id', 'start_date', 'end_date', 'duration_type', 'half_day_period',
            'leave_type', 'assignment_kind', 'deduct_from', 'notes',
        ]), $data);
        $merged['start_date'] = Carbon::parse($merged['start_date'])->toDateString();
        $merged['end_date'] = Carbon::parse($merged['end_date'])->toDateString();
        $merged = $this->applyTotals($employee, $merged);
        $merged = $this->applyDeductionMeta($merged);
        $this->assertNoOverlap($employee->id, $merged['start_date'], $merged['end_date'], (int) $row->id);
        app(LeaveBalanceService::class)->assertCanDeduct(
            $employee,
            $merged['deduct_from'],
            (float) $merged['days_deducted'],
            (int) $row->id,
        );
        $row->update($merged);

        return response()->json($row->fresh(['employee']));
    }

    public function destroy(string $id)
    {
        $row = $this->findScoped($id);
        PayrollCycleSettlementService::assertNotPayrollLocked($row->payroll_run_id, 'leave assignment');
        $row->delete();

        return response()->json(null, 204);
    }

    /** @param array<string, mixed> $data */
    protected function applyTotals(Employee $employee, array $data): array
    {
        $calc = app(LeaveRequestCalculator::class)->calculate(
            $employee,
            $data['start_date'],
            $data['end_date'],
            $data['duration_type'] ?? 'full_day',
            $data['half_day_period'] ?? null,
        );
        $data['total_days'] = $calc['total_days'];
        $data['total_hours'] = $calc['total_hours'];

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function applyDeductionMeta(array $data): array
    {
        $data['deduct_from'] = $this->resolveDeductFrom($data);
        $data['assignment_kind'] = $data['assignment_kind'] ?? 'leave';
        $data['leave_type'] = $this->resolveLeaveType($data);
        $data['days_deducted'] = $data['deduct_from'] === 'unpaid'
            ? 0
            : (float) ($data['total_days'] ?? 0);

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function resolveLeaveType(array $data): string
    {
        $deductFrom = $this->resolveDeductFrom($data);

        return match ($deductFrom) {
            'sick' => 'sick',
            'unpaid' => 'unpaid',
            'annual' => 'annual',
            default => 'other',
        };
    }

    /** @param array<string, mixed> $data */
    protected function resolveDeductFrom(array $data): string
    {
        if (! empty($data['deduct_from'])) {
            return $data['deduct_from'];
        }

        return match ($data['leave_type'] ?? 'annual') {
            'sick' => 'sick',
            'unpaid' => 'unpaid',
            default => 'annual',
        };
    }

    protected function insufficientBalanceMessage(string $pool, float $available, float $requested): string
    {
        $label = match ($pool) {
            'annual' => 'annual leave',
            'sick' => 'sick leave',
            'off_days' => 'off days',
            default => $pool,
        };

        return sprintf(
            'Insufficient %s balance (%s available, %s requested). Cannot assign leave.',
            $label,
            rtrim(rtrim(number_format($available, 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($requested, 2, '.', ''), '0'), '.'),
        );
    }

    protected function assertNoOverlap(int $employeeId, string $start, string $end, ?int $exceptId): void
    {
        $query = EmployeeLeaveDay::query()
            ->where('employee_id', $employeeId)
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start);

        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'dates' => ['This employee already has leave overlapping these dates.'],
            ]);
        }
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        $data = $request->validate([
            'employee_id' => $req . 'integer|exists:employees,id',
            'start_date' => $req . 'date',
            'end_date' => $req . 'date|after_or_equal:start_date',
            'leave_type' => 'nullable|in:annual,sick,unpaid,other',
            'assignment_kind' => 'nullable|in:leave,off_day',
            'deduct_from' => 'nullable|in:annual,sick,off_days,unpaid',
            'duration_type' => 'nullable|in:full_day,half_day',
            'half_day_period' => 'nullable|in:morning,afternoon',
            'notes' => 'nullable|string|max:500',
        ]);

        $data['duration_type'] = $data['duration_type'] ?? 'full_day';
        $data['assignment_kind'] = $data['assignment_kind'] ?? 'leave';

        if ($data['duration_type'] === 'half_day') {
            if (empty($data['half_day_period'])) {
                throw ValidationException::withMessages([
                    'half_day_period' => ['Select morning or afternoon for half day leave.'],
                ]);
            }
            $data['start_date'] = $data['start_date'] ?? $request->input('start_date');
            $data['end_date'] = $data['end_date'] ?? $data['start_date'];
            if ($data['start_date'] !== $data['end_date']) {
                throw ValidationException::withMessages([
                    'end_date' => ['Half day leave must use the same start and end date.'],
                ]);
            }
        } else {
            $data['half_day_period'] = null;
        }

        if ($data['assignment_kind'] === 'off_day') {
            $data['deduct_from'] = $data['deduct_from'] ?? 'off_days';
            $data['leave_type'] = $this->resolveLeaveType($data);
        }

        return $data;
    }
}
