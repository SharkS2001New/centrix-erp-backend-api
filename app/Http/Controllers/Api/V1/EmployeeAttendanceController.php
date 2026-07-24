<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\LatenessWaiverRequest;
use App\Services\Attendance\AttendanceAbsentMaterializer;
use App\Services\Attendance\AttendanceDayPolicy;
use App\Services\Attendance\AttendanceDayReconciler;
use App\Services\Hr\LatenessWaiverApprovalService;
use App\Services\Notifications\ActionRequestService;
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
        $lunchRequired = false;
        if ($employee->shift) {
            $hours = $employee->shift->hoursForDate(
                $data['attendance_date'],
                (bool) ($eval['is_holiday'] ?? false),
            );
            $shiftTimes = $hours;
            $lunchRequired = (bool) ($hours['lunch_required'] ?? false);
            $lunchMinutes = $lunchRequired ? (int) ($hours['lunch_minutes'] ?? 0) : 0;
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
            'lunch_required' => $lunchRequired,
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
        $page = $query->orderByDesc('attendance_date')->paginate($perPage);
        $this->attachPendingWaivers($page->getCollection());

        return response()->json($page);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, EmployeeAttendance>  $rows
     */
    protected function attachPendingWaivers($rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $ids = $rows->pluck('id')->all();
        $pending = LatenessWaiverRequest::query()
            ->whereIn('employee_attendance_id', $ids)
            ->where('status', 'pending')
            ->get()
            ->keyBy('employee_attendance_id');

        foreach ($rows as $row) {
            $req = $pending->get($row->id);
            $row->setAttribute('pending_waiver', $req ? [
                'id' => $req->id,
                'waive' => (bool) $req->waive,
                'reason' => $req->reason,
                'late_minutes' => (int) $req->late_minutes,
                'requested_at' => $req->requested_at,
                'assigned_manager_user_id' => $req->assigned_manager_user_id,
            ] : null);
        }
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
            $this->manualLunchTakenForEmployee(
                $employee,
                $data['attendance_date'],
                ! in_array($data['status'] ?? 'present', ['leave', 'holiday', 'absent'], true),
                $data,
            ),
        );

        return response()->json($attendance->load(['employee', 'branch']), 201);
    }

    /**
     * POST /employee-attendance/bulk — create the same day/times for many employees.
     */
    public function bulkStore(Request $request)
    {
        $request->merge(AttendanceTime::normalizePayload($request->all()));
        $data = $request->validate([
            'attendance_date' => 'required|date',
            'check_in' => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'check_out' => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'status' => 'nullable|in:present,absent,late,half_day,leave,holiday',
            'notes' => 'nullable|string|max:500',
            'all_active' => 'sometimes|boolean',
            'employee_ids' => 'required_unless:all_active,true|array|min:1',
            'employee_ids.*' => 'integer|distinct|exists:employees,id',
            'lunch_taken' => 'sometimes|boolean',
        ]);

        if (empty($data['all_active']) && empty($data['employee_ids'])) {
            throw ValidationException::withMessages([
                'employee_ids' => ['Select at least one employee, or choose all active employees.'],
            ]);
        }

        $status = $data['status'] ?? 'present';
        $needsTimes = ! in_array($status, ['leave', 'holiday', 'absent'], true);
        if ($needsTimes && (empty($data['check_in']) || empty($data['check_out']))) {
            throw ValidationException::withMessages([
                'check_in' => ['Check-in and check-out are required for this status.'],
            ]);
        }

        $user = $request->user();
        $access = app(\App\Services\Auth\UserAccessService::class);
        $employeesQuery = Employee::query()
            ->with('shift')
            ->where(function ($q) {
                $q->where('is_active', '!=', false)->orWhereNull('is_active');
            })
            ->where('employment_status', 'active');

        if ($user) {
            $access->scopeOrganization($employeesQuery, $user, 'organization_id', $request);
            $access->scopeBranchIfLimited($employeesQuery, $user);
        }

        if (! empty($data['all_active'])) {
            $employees = $employeesQuery->orderBy('first_name')->orderBy('last_name')->get();
        } else {
            $ids = array_map('intval', $data['employee_ids'] ?? []);
            $employees = $employeesQuery->whereIn('id', $ids)->get();
            if ($employees->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages([
                    'employee_ids' => ['One or more employees are outside your organization or branch.'],
                ]);
            }
        }

        if ($employees->isEmpty()) {
            throw ValidationException::withMessages([
                'employee_ids' => ['No active employees selected.'],
            ]);
        }

        $policy = app(AttendanceDayPolicy::class);
        $reconciler = app(AttendanceDayReconciler::class);
        $date = $data['attendance_date'];
        $created = [];
        $skipped = [];

        foreach ($employees as $employee) {
            $eval = $policy->evaluate($employee, $date);
            if ($eval['blocks_attendance'] ?? false) {
                $skipped[] = [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->full_name ?: trim($employee->first_name.' '.$employee->last_name),
                    'reason' => $eval['reason'] ?? 'Leave or off day assigned',
                ];
                continue;
            }

            $existing = EmployeeAttendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $date)
                ->first();

            if ($existing) {
                try {
                    PayrollCycleSettlementService::assertNotPayrollLocked(
                        $existing->payroll_run_id ? (int) $existing->payroll_run_id : null,
                        'attendance record',
                    );
                } catch (ValidationException $e) {
                    $skipped[] = [
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->full_name ?: trim($employee->first_name.' '.$employee->last_name),
                        'reason' => 'Attendance for this date is locked on a payroll run',
                    ];
                    continue;
                }
            }

            try {
                $branchId = $employee->branch_id ? (int) $employee->branch_id : null;
                $write = [
                    'branch_id' => $branchId,
                ];
                if ($user) {
                    $this->applyBranchScopeToWriteData($user, $write, $request);
                }

                $row = $reconciler->reconcileManualSpan(
                    $employee,
                    $date,
                    $needsTimes ? ($data['check_in'] ?? null) : null,
                    $needsTimes ? ($data['check_out'] ?? null) : null,
                    'manual',
                    null,
                    $write['branch_id'] ?? $branchId,
                    $data['notes'] ?? null,
                    $status,
                    $this->manualLunchTakenForEmployee($employee, $date, $needsTimes, $data),
                );
                $created[] = [
                    'id' => $row->id,
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->full_name ?: trim($employee->first_name.' '.$employee->last_name),
                    'status' => $row->status,
                    'hours_worked' => $row->hours_worked,
                    'updated' => (bool) $existing,
                ];
            } catch (\Throwable $e) {
                $skipped[] = [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->full_name ?: trim($employee->first_name.' '.$employee->last_name),
                    'reason' => $e->getMessage() ?: 'Could not create attendance',
                ];
            }
        }

        return response()->json([
            'created_count' => count($created),
            'skipped_count' => count($skipped),
            'created' => $created,
            'skipped' => $skipped,
        ], count($created) > 0 ? 201 : 422);
    }

    /**
     * POST /employee-attendance/mark-absents — create absent rows for scheduled days with no attendance.
     * Never marks today or future dates.
     */
    public function markAbsents(Request $request)
    {
        $data = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'attendance_date' => 'nullable|date',
        ]);

        $orgId = $request->user()?->organization_id
            ? (int) $request->user()->organization_id
            : null;

        $materializer = app(AttendanceAbsentMaterializer::class);
        if (! empty($data['attendance_date'])) {
            $result = $materializer->markDate($orgId, $data['attendance_date']);
        } else {
            $from = $data['from_date'] ?? now()->subDay()->toDateString();
            $to = $data['to_date'] ?? $from;
            $result = $materializer->markRange($orgId, $from, $to);
        }

        return response()->json($result);
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

        $nextEmployeeId = (int) ($data['employee_id'] ?? $row->employee_id);
        $sameEmployeeAndDate = $nextEmployeeId === (int) $row->employee_id
            && $date === $row->attendance_date->format('Y-m-d');
        if (! $sameEmployeeAndDate) {
            $this->assertUniqueAttendanceDate($nextEmployeeId, $date, (int) $row->id);
        }

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
            $this->manualLunchTakenForEmployee(
                $employee,
                $date,
                ! in_array($data['status'] ?? $row->status, ['leave', 'holiday', 'absent'], true),
                array_merge(
                    ['lunch_taken' => $row->lunch_status === 'taken'],
                    $data,
                ),
            ),
        );

        // updateOrCreate should hit the same row; if a different row was written, remove the old one.
        if ((int) $attendance->id !== (int) $row->id) {
            $row->delete();
        }

        if (array_key_exists('lateness_waived', $data)) {
            $desired = (bool) $data['lateness_waived'];
            $current = (bool) $attendance->fresh()->lateness_waived;
            if ($desired !== $current) {
                if (! $request->user()) {
                    throw ValidationException::withMessages([
                        'lateness_waived' => ['Authentication required to request a lateness waiver.'],
                    ]);
                }
                $waiver = app(LatenessWaiverApprovalService::class)->submit(
                    $request->user(),
                    $attendance->fresh(),
                    $desired,
                    $data['lateness_waiver_reason'] ?? null,
                );

                return response()->json($attendance->fresh(['employee', 'branch'])->setAttribute('pending_waiver', [
                    'id' => $waiver->id,
                    'waive' => (bool) $waiver->waive,
                    'reason' => $waiver->reason,
                    'status' => $waiver->status,
                ]));
            }
        }

        return response()->json($attendance->fresh(['employee', 'branch']));
    }

    /** POST /employee-attendance/{id}/waive-lateness — submit for manager approval (does not apply yet). */
    public function waiveLateness(Request $request, string $id)
    {
        $row = $this->findScoped($id);
        $data = $request->validate([
            'lateness_waived' => 'required|boolean',
            'lateness_waiver_reason' => 'nullable|string|max:500',
        ]);

        $waiver = app(LatenessWaiverApprovalService::class)->submit(
            $request->user(),
            $row,
            (bool) $data['lateness_waived'],
            $data['lateness_waiver_reason'] ?? null,
        );

        return response()->json([
            'message' => 'Lateness waiver submitted for manager approval.',
            'waiver_request' => $waiver,
            'attendance' => $row->fresh(['employee', 'branch']),
        ], 202);
    }

    /**
     * POST /employee-attendance/bulk-waive-lateness — submit waiver requests (one shared reason).
     */
    public function bulkWaiveLateness(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer|distinct',
            'lateness_waived' => 'required|boolean',
            'lateness_waiver_reason' => 'nullable|string|max:500',
        ]);

        $ids = array_map('intval', $data['ids']);
        $rows = $this->scopedAttendanceByIds($ids, $request);
        $service = app(LatenessWaiverApprovalService::class);
        $submitted = [];
        $skipped = [];

        foreach ($rows as $row) {
            $label = $row->employee
                ? ($row->employee->full_name ?: trim($row->employee->first_name.' '.$row->employee->last_name))
                : ('#'.$row->id);

            try {
                $waiver = $service->submit(
                    $request->user(),
                    $row,
                    (bool) $data['lateness_waived'],
                    $data['lateness_waiver_reason'] ?? null,
                );
                $submitted[] = [
                    'id' => $row->id,
                    'employee_name' => $label,
                    'waiver_request_id' => $waiver->id,
                ];
            } catch (\Throwable $e) {
                $skipped[] = [
                    'id' => $row->id,
                    'employee_name' => $label,
                    'reason' => $e->getMessage() ?: 'Could not submit waiver request',
                ];
            }
        }

        foreach ($ids as $id) {
            if ($rows->firstWhere('id', $id)) {
                continue;
            }
            $skipped[] = [
                'id' => $id,
                'employee_name' => '#'.$id,
                'reason' => 'Attendance not found or outside your access',
            ];
        }

        return response()->json([
            'submitted_count' => count($submitted),
            'skipped_count' => count($skipped),
            'updated_count' => count($submitted), // FE compat
            'submitted' => $submitted,
            'updated' => $submitted,
            'skipped' => $skipped,
        ], count($submitted) > 0 ? 202 : 422);
    }

    /** POST /lateness-waiver-requests/{id}/approve */
    public function approveWaiverRequest(Request $request, string $id)
    {
        $waiver = $this->findScopedWaiverRequest($id, $request);
        $approved = app(LatenessWaiverApprovalService::class)->approve($waiver, $request->user());

        app(ActionRequestService::class)->markResolvedFromDomain(
            'lateness_waiver',
            'lateness_waiver_request',
            (int) $approved->id,
            'approved',
            $request->user(),
        );

        return response()->json($approved->load(['attendance.employee', 'employee']));
    }

    /** POST /lateness-waiver-requests/{id}/reject */
    public function rejectWaiverRequest(Request $request, string $id)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);
        $waiver = $this->findScopedWaiverRequest($id, $request);
        $rejected = app(LatenessWaiverApprovalService::class)->reject(
            $waiver,
            $request->user(),
            $data['reason'] ?? null,
        );

        app(ActionRequestService::class)->markResolvedFromDomain(
            'lateness_waiver',
            'lateness_waiver_request',
            (int) $rejected->id,
            'rejected',
            $request->user(),
            $data['reason'] ?? null,
        );

        return response()->json($rejected->load(['attendance.employee', 'employee']));
    }

    protected function findScopedWaiverRequest(string $id, Request $request): LatenessWaiverRequest
    {
        $query = LatenessWaiverRequest::query()->where('id', $id);
        if ($orgId = $request->user()?->organization_id) {
            $query->where('organization_id', $orgId);
        }

        $waiver = $query->firstOrFail();

        return $waiver;
    }

    /**
     * POST /employee-attendance/bulk-delete
     */
    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer|distinct',
        ]);

        $ids = array_map('intval', $data['ids']);
        $rows = $this->scopedAttendanceByIds($ids, $request);
        $deleted = [];
        $skipped = [];

        foreach ($rows as $row) {
            $label = $row->employee
                ? ($row->employee->full_name ?: trim($row->employee->first_name.' '.$row->employee->last_name))
                : ('#'.$row->id);

            try {
                PayrollCycleSettlementService::assertNotPayrollLocked(
                    $row->payroll_run_id ? (int) $row->payroll_run_id : null,
                    'attendance record',
                );
                $this->deleteAttendanceRow($row);
                $deleted[] = [
                    'id' => $row->id,
                    'employee_name' => $label,
                ];
            } catch (\Throwable $e) {
                $skipped[] = [
                    'id' => $row->id,
                    'employee_name' => $label,
                    'reason' => $e->getMessage() ?: 'Could not delete',
                ];
            }
        }

        foreach ($ids as $id) {
            if ($rows->firstWhere('id', $id)) {
                continue;
            }
            $skipped[] = [
                'id' => $id,
                'employee_name' => '#'.$id,
                'reason' => 'Attendance not found or outside your access',
            ];
        }

        return response()->json([
            'deleted_count' => count($deleted),
            'skipped_count' => count($skipped),
            'deleted' => $deleted,
            'skipped' => $skipped,
        ], count($deleted) > 0 ? 200 : 422);
    }

    public function destroy(string $id)
    {
        $row = $this->findScoped($id);
        PayrollCycleSettlementService::assertNotPayrollLocked($row->payroll_run_id, 'attendance record');
        $this->deleteAttendanceRow($row);

        return response()->json(null, 204);
    }

    /**
     * @param  list<int>  $ids
     * @return \Illuminate\Support\Collection<int, EmployeeAttendance>
     */
    protected function scopedAttendanceByIds(array $ids, Request $request)
    {
        $query = EmployeeAttendance::query()
            ->with(['employee:id,organization_id,full_name,first_name,last_name,employee_code'])
            ->whereIn('id', $ids);

        if ($orgId = $request->user()?->organization_id) {
            $query->where('organization_id', $orgId);
        }

        if ($request->user()) {
            app(\App\Services\Auth\UserAccessService::class)
                ->applyBranchListFilter($query, $request->user(), $request);
        }

        return $query->get();
    }

    protected function deleteAttendanceRow(EmployeeAttendance $row): void
    {
        $employeeId = (int) $row->employee_id;
        $date = $row->attendance_date instanceof \Carbon\Carbon
            ? $row->attendance_date->toDateString()
            : (string) $row->attendance_date;

        \Illuminate\Support\Facades\DB::transaction(function () use ($row, $employeeId, $date) {
            \App\Models\EmployeeClockSession::query()
                ->where('employee_id', $employeeId)
                ->where(function ($q) use ($date) {
                    $q->whereDate('clock_in_at', $date)
                        ->orWhereDate('clock_out_at', $date);
                })
                ->delete();

            \App\Models\EmployeeOvertime::query()
                ->where('employee_id', $employeeId)
                ->whereDate('work_date', $date)
                ->where('status', 'pending')
                ->whereNull('payroll_run_id')
                ->where('notes', 'like', AttendanceDayReconciler::AUTO_OT_NOTE_PREFIX.'%')
                ->delete();

            $row->delete();
        });
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
            'lateness_waived' => 'nullable|boolean',
            'lateness_waiver_reason' => 'nullable|string|max:500',
            'lunch_taken' => 'nullable|boolean',
        ]);
    }

    /**
     * Manual lunch flag only applies when the employee's shift requires lunch that day.
     */
    protected function manualLunchTakenForEmployee(
        Employee $employee,
        string $date,
        bool $needsTimes,
        array $data,
    ): ?bool {
        if (! $needsTimes) {
            return null;
        }

        $employee->loadMissing('shift');
        if (! $employee->shift) {
            return array_key_exists('lunch_taken', $data)
                ? filter_var($data['lunch_taken'], FILTER_VALIDATE_BOOLEAN)
                : true;
        }

        $isHoliday = \App\Models\OrganizationHoliday::query()
            ->where('organization_id', $employee->organization_id)
            ->where('is_active', true)
            ->whereDate('holiday_date', $date)
            ->exists();
        $hours = $employee->shift->hoursForDate($date, $isHoliday);
        if (! ($hours['lunch_required'] ?? false) || (int) ($hours['lunch_minutes'] ?? 0) <= 0) {
            return null;
        }

        return array_key_exists('lunch_taken', $data)
            ? filter_var($data['lunch_taken'], FILTER_VALIDATE_BOOLEAN)
            : true;
    }
}
