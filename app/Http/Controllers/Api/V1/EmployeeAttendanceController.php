<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Services\Attendance\AttendanceDayPolicy;
use App\Services\Payroll\PayrollCycleSettlementService;
use App\Support\AttendanceHours;
use App\Support\AttendanceTime;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeAttendanceController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return EmployeeAttendance::class;
    }

    public function dayPreview(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'attendance_date' => 'required|date',
        ]);
        $employee = $this->findOrgEmployee($data['employee_id'], $request)->load('shift');
        $eval = app(AttendanceDayPolicy::class)->evaluate($employee, $data['attendance_date']);
        $existing = EmployeeAttendance::query()
            ->where('employee_id', $data['employee_id'])
            ->whereDate('attendance_date', $data['attendance_date'])
            ->first();

        $shiftHours = null;
        $shiftTimes = null;
        if ($employee->shift) {
            $hours = $employee->shift->hoursForDate(
                $data['attendance_date'],
                (bool) ($eval['is_holiday'] ?? false),
            );
            $shiftTimes = $hours;
            $shiftHours = app(\App\Services\Attendance\LeaveRequestCalculator::class)
                ->hoursBetweenTimes(
                    $hours['start_time'],
                    $hours['end_time'],
                    (bool) $hours['crosses_midnight'],
                );
        }

        return response()->json(array_merge($eval, [
            'has_existing_attendance' => (bool) $existing,
            'existing_attendance' => $existing ? [
                'id' => $existing->id,
                'status' => $existing->status,
                'source' => $existing->source,
                'hours_worked' => $existing->hours_worked,
            ] : null,
            'expected_hours' => $shiftHours,
            'shift_times' => $shiftTimes,
        ]));
    }

    public function index(Request $request)
    {
        $query = EmployeeAttendance::query()->with([
            'employee:id,organization_id,full_name,first_name,last_name,employee_code,department_id,branch_id',
            'branch:id,organization_id,branch_name',
        ]);

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

        if ($request->filled('from_date')) {
            $query->whereDate('attendance_date', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('attendance_date', '<=', $request->input('to_date'));
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('status', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%")
                    ->orWhere('source', 'like', "%{$q}%")
                    ->orWhereHas('employee', function ($emp) use ($q) {
                        $emp->where('full_name', 'like', "%{$q}%")
                            ->orWhere('first_name', 'like', "%{$q}%")
                            ->orWhere('last_name', 'like', "%{$q}%")
                            ->orWhere('employee_code', 'like', "%{$q}%");
                    });
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderByDesc('attendance_date')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $request->merge(AttendanceTime::normalizePayload($request->all()));
        $data = $this->applyHours($this->validated($request));
        $employee = $this->findOrgEmployee($data['employee_id'], $request)->load('shift');
        app(AttendanceDayPolicy::class)->assertCanCreateAttendance($employee, $data['attendance_date']);
        $data = $this->applyDayPolicy($employee, $data);
        $data['organization_id'] = $data['organization_id'] ?? $employee->organization_id;
        if (empty($data['branch_id'])) {
            $data['branch_id'] = $employee->branch_id;
        }
        if ($request->user()) {
            $this->applyBranchScopeToWriteData($request->user(), $data, $request);
        }
        $data['source'] = $data['source'] ?? 'manual';

        $this->assertUniqueAttendanceDate(
            (int) $data['employee_id'],
            $data['attendance_date'],
        );

        return response()->json(
            EmployeeAttendance::create($data)->load(['employee', 'branch']),
            201,
        );
    }

    public function update(Request $request, string $id)
    {
        $row = $this->findScoped($id);
        PayrollCycleSettlementService::assertNotPayrollLocked($row->payroll_run_id, 'attendance record');
        $request->merge(AttendanceTime::normalizePayload($request->all()));
        $data = $this->applyHours($this->validated($request, updating: true));
        $employee = $this->findOrgEmployee($data['employee_id'] ?? $row->employee_id, $request)->load('shift');
        $data['attendance_date'] = $data['attendance_date'] ?? $row->attendance_date->format('Y-m-d');
        app(AttendanceDayPolicy::class)->assertCanCreateAttendance($employee, $data['attendance_date']);
        $this->assertUniqueAttendanceDate(
            (int) ($data['employee_id'] ?? $row->employee_id),
            $data['attendance_date'],
            (int) $row->id,
        );
        $data = $this->applyDayPolicy($employee, $data);
        if ($request->user()) {
            $this->applyBranchScopeToWriteData($request->user(), $data, $request);
        }
        $row->update($data);

        return response()->json($row->fresh(['employee', 'branch']));
    }

    public function destroy(string $id)
    {
        $row = $this->findScoped($id);
        PayrollCycleSettlementService::assertNotPayrollLocked($row->payroll_run_id, 'attendance record');
        $row->delete();

        return response()->json(null, 204);
    }

    /** @param array<string, mixed> $data */
    protected function applyHours(array $data): array
    {
        $computed = AttendanceHours::fromTimeStrings(
            $data['check_in'] ?? null,
            $data['check_out'] ?? null,
        );
        if ($computed !== null) {
            $data['hours_worked'] = $computed;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function applyDayPolicy(Employee $employee, array $data): array
    {
        $date = $data['attendance_date'] ?? now()->toDateString();
        $policy = app(AttendanceDayPolicy::class);
        $eval = $policy->evaluate($employee, $date);

        $status = $data['status'] ?? $eval['suggested_status'];
        if (in_array($status, ['leave', 'holiday', 'absent'], true)) {
            $data['status'] = $status;
            $data['check_in'] = null;
            $data['check_out'] = null;
            $data['hours_worked'] = 0;

            return $data;
        }

        if (! $eval['should_work'] && ($data['status'] ?? 'present') === 'present') {
            $data['status'] = $eval['suggested_status'];
            $data['hours_worked'] = 0;
            $data['check_in'] = null;
            $data['check_out'] = null;

            return $data;
        }

        return $data;
    }

    protected function assertUniqueAttendanceDate(
        int $employeeId,
        string $attendanceDate,
        ?int $exceptId = null,
    ): void {
        $query = EmployeeAttendance::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', $attendanceDate);

        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'attendance_date' => [
                    'This employee already has an attendance record for this date. Edit the existing record instead.',
                ],
            ]);
        }
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        return $request->validate([
            'employee_id' => $req . 'integer|exists:employees,id',
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'attendance_date' => $req . 'date',
            'check_in' => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'check_out' => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'status' => 'nullable|in:present,absent,late,half_day,leave,holiday',
            'source' => 'nullable|in:manual,clock_device,company_mobile,field_rep',
            'device_identifier' => 'nullable|string|max:100',
            'hours_worked' => 'nullable|numeric|min:0|max:24',
            'notes' => 'nullable|string|max:500',
        ]);
    }
}
