<?php

namespace App\Services\Attendance;

use App\Models\AttendanceClockDevice;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeClockSession;
use App\Models\EmployeeOvertime;
use App\Models\HikvisionAccessEvent;
use App\Services\Payroll\PayrollCycleSettlementService;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ingest biometric / terminal punches into employee clock sessions.
 * Maps terminal employee numbers to Centrix employee_code (or payroll_number).
 */
class AttendanceClockPunchService
{
    public function __construct(
        protected AttendanceDayPolicy $dayPolicy,
        protected AttendanceDayReconciler $reconciler,
        protected AttendancePunchWindowResolver $windows,
    ) {
    }

    /**
     * @param  array{
     *   organization_id: int,
     *   employee_id?: int|null,
     *   employee_code?: string|null,
     *   device_no?: string|null,
     *   device_identifier?: string|null,
     *   punched_at?: string|Carbon|null,
     *   direction?: string|null,
     *   branch_id?: int|null,
     * }  $payload
     * @return array{action: string, session: EmployeeClockSession, attendance?: mixed}
     */
    public function punch(array $payload): array
    {
        $orgId = (int) $payload['organization_id'];
        $employee = $this->resolveEmployee($orgId, $payload);
        $deviceNo = $this->resolveDeviceNo($orgId, $payload);
        $punchedAt = $this->resolvePunchedAt($payload['punched_at'] ?? null);
        $direction = strtolower(trim((string) ($payload['direction'] ?? 'auto')));
        if (! in_array($direction, ['auto', 'in', 'out'], true)) {
            throw ValidationException::withMessages([
                'direction' => 'Direction must be auto, in, or out.',
            ]);
        }

        $open = EmployeeClockSession::query()
            ->where('employee_id', $employee->id)
            ->whereNull('clock_out_at')
            ->orderByDesc('clock_in_at')
            ->first();

        // Same-hour extra scans must not close anything. Closing first wrote 23:59:59
        // whenever timezone made the open session look like a different calendar day.
        if ($this->windows->hasActivityInSameHour($employee, $punchedAt)) {
            $session = $open ?? EmployeeClockSession::query()
                ->where('employee_id', $employee->id)
                ->orderByDesc('clock_in_at')
                ->first();
            if ($session) {
                return [
                    'action' => 'ignored',
                    'session' => $session->load('employee'),
                    'attendance' => $session->attendance,
                ];
            }
        }

        if ($open && $this->windows->isStaleOpenSession($open, $punchedAt)) {
            // Leave yesterday without a fabricated midnight clock-out (HR shows 23:59).
            $open = null;
        }

        if ($direction === 'auto') {
            $direction = $this->windows->resolve($employee, $punchedAt, $open);
        }

        if ($direction === AttendancePunchWindowResolver::ACTION_IGNORE) {
            $session = $open ?? EmployeeClockSession::query()
                ->where('employee_id', $employee->id)
                ->orderByDesc('clock_in_at')
                ->first();
            if (! $session) {
                $direction = AttendancePunchWindowResolver::ACTION_IN;
            } else {
                return [
                    'action' => 'ignored',
                    'session' => $session->load('employee'),
                    'attendance' => $session->attendance,
                ];
            }
        }

        if ($direction === 'out' && $open === null) {
            $direction = 'in';
        }

        if ($direction === 'in') {
            return $this->clockIn($employee, $punchedAt, $deviceNo, $payload['branch_id'] ?? null, $open);
        }

        return $this->clockOut($employee, $punchedAt, $deviceNo, $open);
    }

    public function deleteSession(int $organizationId, int $sessionId): void
    {
        $session = EmployeeClockSession::query()
            ->where('organization_id', $organizationId)
            ->where('id', $sessionId)
            ->first();

        if (! $session) {
            abort(404, 'Clock session not found.');
        }

        $employee = Employee::with('shift')->find($session->employee_id);
        if (! $employee || (int) $employee->organization_id !== $organizationId) {
            abort(404, 'Clock session not found.');
        }

        $clockIn = AppTimezone::normalize($session->clock_in_at) ?? Carbon::parse($session->clock_in_at);
        $date = $clockIn->timezone(AppTimezone::name())->toDateString();
        $attendance = $session->attendance_id
            ? EmployeeAttendance::query()->find($session->attendance_id)
            : EmployeeAttendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $date)
                ->first();

        if ($attendance) {
            PayrollCycleSettlementService::assertNotPayrollLocked(
                $attendance->payroll_run_id,
                'attendance punch',
            );
        }

        DB::transaction(function () use ($session, $employee, $date, $attendance) {
            HikvisionAccessEvent::query()
                ->where('clock_session_id', $session->id)
                ->update(['clock_session_id' => null]);

            $session->delete();

            $remaining = EmployeeClockSession::query()
                ->where('employee_id', $employee->id)
                ->where(function ($q) use ($date) {
                    $q->whereDate('clock_in_at', $date)
                        ->orWhereDate('clock_out_at', $date);
                })
                ->orderBy('clock_in_at')
                ->get();

            if ($remaining->isEmpty()) {
                $this->deletePendingAutoOvertime((int) $employee->id, $date);
                if ($attendance) {
                    $attendance->delete();
                }

                return;
            }

            $closed = $remaining->filter(fn (EmployeeClockSession $row) => $row->clock_out_at !== null);
            $open = $remaining->first(fn (EmployeeClockSession $row) => $row->clock_out_at === null);
            $source = (string) ($remaining->first()?->source ?: 'clock_device');
            $deviceNo = $remaining->first()?->device_identifier;
            $branchId = $remaining->first()?->branch_id ? (int) $remaining->first()->branch_id : null;

            if ($closed->isNotEmpty()) {
                $this->reconciler->reconcileFromSessions(
                    $employee,
                    $date,
                    $source,
                    $deviceNo,
                    $branchId,
                );

                return;
            }

            $openAt = AppTimezone::normalize($open->clock_in_at) ?? Carbon::parse($open->clock_in_at);
            $this->deletePendingAutoOvertime((int) $employee->id, $date);
            $updated = $this->reconciler->recordOpenClockIn(
                $employee,
                $openAt,
                $source,
                $open->device_identifier,
                $open->branch_id ? (int) $open->branch_id : null,
                true,
            );
            $open->attendance_id = $updated->id;
            $open->save();
        });
    }

    protected function deletePendingAutoOvertime(int $employeeId, string $date): void
    {
        EmployeeOvertime::query()
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $date)
            ->where('status', 'pending')
            ->whereNull('payroll_run_id')
            ->where('notes', 'like', AttendanceDayReconciler::AUTO_OT_NOTE_PREFIX.'%')
            ->delete();
    }

    /**
     * @param  array{employee_id?: int|null, employee_code?: string|null}  $payload
     */
    protected function resolveEmployee(int $orgId, array $payload): Employee
    {
        if (! empty($payload['employee_id'])) {
            $employee = Employee::with('shift')->find((int) $payload['employee_id']);
            if (! $employee || (int) $employee->organization_id !== $orgId) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Employee not found in this organization.',
                ]);
            }

            return $employee;
        }

        $code = trim((string) ($payload['employee_code'] ?? ''));
        if ($code === '') {
            throw ValidationException::withMessages([
                'employee_code' => 'Provide employee_code or employee_id.',
            ]);
        }

        $variants = \App\Services\Attendance\Hikvision\HikvisionService::employeeNoLookupVariants($code);

        $employee = Employee::with('shift')
            ->where('organization_id', $orgId)
            ->where(function ($q) use ($variants) {
                $q->whereIn('employee_code', $variants)
                    ->orWhereIn('payroll_number', $variants);
            })
            ->first();

        if (! $employee) {
            foreach ($variants as $variant) {
                $employee = Employee::with('shift')
                    ->where('organization_id', $orgId)
                    ->where(function ($q) use ($variant) {
                        $q->where('employee_code', 'like', '%'.$variant)
                            ->orWhere('payroll_number', 'like', '%'.$variant);
                    })
                    ->orderBy('id')
                    ->first();
                if ($employee) {
                    break;
                }
            }
        }

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_code' => "No Centrix employee matches terminal ID \"{$code}\". Enroll the same employee_code on the device.",
            ]);
        }

        return $employee;
    }

    /**
     * @param  array{device_no?: string|null, device_identifier?: string|null}  $payload
     */
    protected function resolveDeviceNo(int $orgId, array $payload): ?string
    {
        $deviceNo = trim((string) ($payload['device_no'] ?? $payload['device_identifier'] ?? ''));
        if ($deviceNo === '') {
            return null;
        }

        $registered = AttendanceClockDevice::query()
            ->where('organization_id', $orgId)
            ->where('device_no', $deviceNo)
            ->where('is_active', true)
            ->exists();

        if (! $registered) {
            throw ValidationException::withMessages([
                'device_no' => "Clock device \"{$deviceNo}\" is not registered (or inactive) in Centrix HR settings.",
            ]);
        }

        return $deviceNo;
    }

    protected function resolvePunchedAt(mixed $raw): Carbon
    {
        if ($raw === null || $raw === '') {
            return AppTimezone::now();
        }

        if ($raw instanceof Carbon) {
            $at = $raw->copy()->timezone(AppTimezone::name());
        } else {
            $at = AppTimezone::fromDeviceWallClock($raw) ?? AppTimezone::normalize($raw);
            if ($at === null) {
                throw ValidationException::withMessages([
                    'punched_at' => 'Invalid punched_at timestamp.',
                ]);
            }
        }

        // Allow small clock skew between the terminal / office PC and Centrix.
        if ($at->isFuture() && $at->diffInMinutes(AppTimezone::now()) > 15) {
            throw ValidationException::withMessages([
                'punched_at' => 'Punch time cannot be in the future.',
            ]);
        }

        return $at;
    }

    /**
     * @return array{action: string, session: EmployeeClockSession, attendance?: mixed}
     */
    protected function clockIn(
        Employee $employee,
        Carbon $punchedAt,
        ?string $deviceNo,
        mixed $branchId,
        ?EmployeeClockSession $open,
    ): array {
        if ($open) {
            throw ValidationException::withMessages([
                'direction' => 'Employee already has an open clock-in session.',
            ]);
        }

        // Idempotent: same device punch second already recorded as clock-in.
        $duplicate = EmployeeClockSession::query()
            ->where('employee_id', $employee->id)
            ->where('clock_in_at', $punchedAt)
            ->first();
        if ($duplicate) {
            return ['action' => 'in', 'session' => $duplicate->load('employee')];
        }

        try {
            $this->dayPolicy->assertCanClockIn($employee, $punchedAt->toIso8601String());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'employee_id' => $e->getMessage(),
            ]);
        }

        $session = EmployeeClockSession::create([
            'employee_id' => $employee->id,
            'organization_id' => $employee->organization_id,
            'branch_id' => $branchId ?? $employee->branch_id,
            'source' => 'clock_device',
            'clock_in_at' => $punchedAt,
            'device_identifier' => $deviceNo,
        ]);

        $attendance = $this->reconciler->recordOpenClockIn(
            $employee,
            $punchedAt,
            'clock_device',
            $deviceNo,
            $session->branch_id ? (int) $session->branch_id : null,
        );
        $session->attendance_id = $attendance->id;
        $session->save();

        return [
            'action' => 'in',
            'session' => $session->load('employee'),
            'attendance' => $attendance,
        ];
    }

    /**
     * @return array{action: string, session: EmployeeClockSession, attendance: mixed}
     */
    protected function clockOut(
        Employee $employee,
        Carbon $punchedAt,
        ?string $deviceNo,
        ?EmployeeClockSession $open,
    ): array {
        if (! $open) {
            throw ValidationException::withMessages([
                'direction' => 'No open clock-in session for this employee.',
            ]);
        }

        if ($open->clock_out_at) {
            return [
                'action' => 'out',
                'session' => $open->load(['employee', 'attendance']),
                'attendance' => $open->attendance,
            ];
        }

        if ($punchedAt->lt(Carbon::parse($open->clock_in_at))) {
            throw ValidationException::withMessages([
                'punched_at' => 'Clock-out cannot be before clock-in.',
            ]);
        }

        $open->clock_out_at = $punchedAt;
        if ($deviceNo) {
            $open->device_identifier = $deviceNo;
        }
        $open->save();

        $attendanceDate = AppTimezone::normalize($open->clock_in_at)?->toDateString()
            ?? $punchedAt->timezone(AppTimezone::name())->toDateString();
        $attendance = $this->reconciler->reconcileFromSessions(
            $employee,
            $attendanceDate,
            'clock_device',
            $open->device_identifier,
            $open->branch_id ? (int) $open->branch_id : null,
        );

        $open->attendance_id = $attendance->id;
        $open->save();

        return [
            'action' => 'out',
            'session' => $open->load(['employee', 'attendance']),
            'attendance' => $attendance,
        ];
    }
}
