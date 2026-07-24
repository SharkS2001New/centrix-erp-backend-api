<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Services\Attendance\AttendanceDayPolicy;
use App\Services\Attendance\AttendanceDayReconciler;
use App\Services\Payroll\PayrollCycleSettlementService;
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
        $lunchMinutes = null;
        if ($employee->shift) {
            $hours = $employee->shift->hoursForDate(
                $data['attendance_date'],
                (bool) ($eval['is_holiday'] ?? false),
            );
            $shiftTimes = $hours;
            $lunchMinutes = (int) ($hours['lunch_minutes'] ?? 0);
            $shiftHours = app(AttendanceDayReconciler::class)
                ->expectedPaidHours($employee, $data['attendance_date']);
        }

        return response()->json(array_merge($eval, [
            'has_existing_attendance' => (bool) $existing,
            'existing_attendance' => $existing ? [
                'id' => $existing->id,
                'status' => $existing->status,
                'source' => $existing->source,
                'hours_worked' => $existing->hours_worked,
                'expected_hours' => $existing->expected_hours,
                'late_minutes' => $existing->late_minutes,
                'lunch_status' => $existing->lunch_status,
                'lunch_minutes' => $existing->lunch_minutes,
                'overtime_minutes' => $existing->overtime_minutes,
            ] : null,
            'expected_hours' => $shiftHours,
            'shift_times' => $shiftTimes,
            'lunch_minutes' => $lunchMinutes,
            'bank_lunch_as_work' => (bool) ($employee->bank_lunch_as_work ?? false),
        ]));
    }

    public function index(Request $request)
    {
        $query = EmployeeAttendance::query()->with([
            'employee:id,organization_id,full_name,first_name,last_name,employee_code,department_id,branch_id,bank_lunch_as_work',
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
        $data = $this->validated($request);
        $employee = $this->findOrgEmployee($data['employee_id'], $request)->load('shift');
        app(AttendanceDayPolicy::class)->assertCanCreateAttendance($employee, $data['attendance_date']);
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

        $attendance = app(AttendanceDayReconciler::class)->reconcileManualSpan(
            $employee,
            $data['attendance_date'],
            $data['check_in'] ?? null,
            $data['check_out'] ?? null,
            $data['source'],
            $data['device_identifier'] ?? null,
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            $data['notes'] ?? null,
            $data['status'] ?? null,
        );

        return response()->json($attendance->load(['employee', 'branch']), 201);
    }

    public function update(Request $request, string $id)
    {
        $row = $this->findScoped($id);
        PayrollCycleSettlementService::assertNotPayrollLocked($row->payroll_run_id, 'attendance record');
        $request->merge(AttendanceTime::normalizePayload($request->all()));
        $data = $this->validated($request, updating: true);
        $employee = $this->findOrgEmployee($data['employee_id'] ?? $row->employee_id, $request)->load('shift');
        $date = $data['attendance_date'] ?? $row->attendance_date->format('Y-m-d');
        app(AttendanceDayPolicy::class)->assertCanCreateAttendance($employee, $date);
        $this->assertUniqueAttendanceDate(
            (int) ($data['employee_id'] ?? $row->employee_id),
            $date,
            (int) $row->id,
        );
        if ($request->user()) {
            $this->applyBranchScopeToWriteData($request->user(), $data, $request);
        }

        $attendance = app(AttendanceDayReconciler::class)->reconcileManualSpan(
            $employee,
            $date,
            array_key_exists('check_in', $data) ? ($data['check_in'] ?? null) : $row->check_in,
            array_key_exists('check_out', $data) ? ($data['check_out'] ?? null) : $row->check_out,
            $data['source'] ?? $row->source ?? 'manual',
            $data['device_identifier'] ?? $row->device_identifier,
            isset($data['branch_id']) ? (int) $data['branch_id'] : ($row->branch_id ? (int) $row->branch_id : null),
            array_key_exists('notes', $data) ? ($data['notes'] ?? null) : $row->notes,
            $data['status'] ?? $row->status,
        );

        if ((int) $attendance->id !== (int) $row->id) {
            $row->delete();
        }

        return response()->json($attendance->fresh(['employee', 'branch']));
    }

    public function destroy(string $id)
    {
        $row = $this->findScoped($id);
        PayrollCycleSettlementService::assertNotPayrollLocked($row->payroll_run_id, 'attendance record');
        $row->delete();

        return response()->json(null, 204);
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
            'notes' => 'nullable|string|max:500',
        ]);
    }
}
