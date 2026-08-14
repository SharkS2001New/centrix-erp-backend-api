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

        $hrOverride = (bool) ($payload['hr_override'] ?? false);
        $source = $hrOverride ? 'hr_applied' : 'clock_device';

        $open = EmployeeClockSession::query()
            ->where('employee_id', $employee->id)
            ->whereNull('clock_out_at')
            ->orderByDesc('clock_in_at')
            ->first();

        // Same-hour extra scans must not close anything. Closing first wrote 23:59:59
        // whenever timezone made the open session look like a different calendar day.
        if (! $hrOverride && $this->windows->hasActivityInSameHour($employee, $punchedAt)) {
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

        if ($hrOverride && in_array($direction, [
            AttendancePunchWindowResolver::ACTION_IGNORE,
            AttendancePunchWindowResolver::ACTION_MISSED,
            'out',
        ], true) && $open === null) {
            $direction = AttendancePunchWindowResolver::ACTION_IN;
        }

        if ($hrOverride && in_array($direction, [
            AttendancePunchWindowResolver::ACTION_IGNORE,
            AttendancePunchWindowResolver::ACTION_MISSED,
        ], true) && $open) {
            $direction = 'out';
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

        if ($direction === AttendancePunchWindowResolver::ACTION_MISSED) {
            $session = $open ?? EmployeeClockSession::query()
                ->where('employee_id', $employee->id)
                ->orderByDesc('clock_in_at')
                ->first();

            return [
                'action' => 'missed',
                'session' => $session?->load('employee'),
                'attendance' => $session?->attendance,
            ];
        }

        if ($direction === 'out' && $open === null) {
            return [
                'action' => 'missed',
                'session' => null,
                'attendance' => null,
            ];
        }

        if ($direction === 'in') {
            return $this->clockIn($employee, $punchedAt, $deviceNo, $payload['branch_id'] ?? null, $open, $source);
        }

        return $this->clockOut($employee, $punchedAt, $deviceNo, $open, $source);
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

    /**
     * HR edit of a clock session (clock-in / clock-out times) then rebuild the day.
     *
     * @param  array{clock_in_at?: mixed, clock_out_at?: mixed, confirm_reconciliation?: bool}  $payload
     * @return array{action: string, session: EmployeeClockSession, attendance?: mixed}
     */
    public function updateSession(int $organizationId, int $sessionId, array $payload): array
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

        $attendance = $session->attendance_id
            ? EmployeeAttendance::query()->find($session->attendance_id)
            : null;
        PayrollCycleSettlementService::assertNotPayrollLocked(
            $attendance?->payroll_run_id,
            'attendance punch',
        );

        $clockIn = array_key_exists('clock_in_at', $payload) && $payload['clock_in_at'] !== null && $payload['clock_in_at'] !== ''
            ? $this->resolvePunchedAt($payload['clock_in_at'])
            : (AppTimezone::normalize($session->clock_in_at) ?? Carbon::parse($session->clock_in_at));

        $clockOut = $session->clock_out_at
            ? (AppTimezone::normalize($session->clock_out_at) ?? Carbon::parse($session->clock_out_at))
            : null;
        if (array_key_exists('clock_out_at', $payload)) {
            $rawOut = $payload['clock_out_at'];
            $clockOut = ($rawOut === null || $rawOut === '')
                ? null
                : $this->resolvePunchedAt($rawOut);
        }

        if ($clockOut && $clockOut->lt($clockIn)) {
            throw ValidationException::withMessages([
                'clock_out_at' => 'Clock-out cannot be before clock-in.',
            ]);
        }

        $session->clock_in_at = $clockIn;
        $session->clock_out_at = $clockOut;
        if ($clockOut) {
            $session->clock_out_kind = EmployeeClockSession::CLOCK_OUT_KIND_HR;
        }
        if (! empty($payload['confirm_reconciliation']) || $clockOut) {
            $session->needs_reconciliation = false;
        }
        $session->save();

        $date = $clockIn->copy()->timezone(AppTimezone::name())->toDateString();
        if ($clockOut) {
            $attendance = $this->reconciler->reconcileFromSessions(
                $employee,
                $date,
                (string) ($session->source ?: 'clock_device'),
                $session->device_identifier,
                $session->branch_id ? (int) $session->branch_id : null,
            );
        } else {
            $attendance = $this->reconciler->recordOpenClockIn(
                $employee,
                $clockIn,
                (string) ($session->source ?: 'clock_device'),
                $session->device_identifier,
                $session->branch_id ? (int) $session->branch_id : null,
                true,
            );
        }
        $session->attendance_id = $attendance->id;
        $session->save();

        return [
            'action' => 'updated',
            'session' => $session->fresh()->load('employee'),
            'attendance' => $attendance,
        ];
    }

    /**
     * Align the day's first clock-in and last clock-out with HR-edited attendance times.
     */
    public function adjustDayPunchTimes(
        Employee $employee,
        string $date,
        ?string $checkIn,
        ?string $checkOut,
    ): void {
        if (! $checkIn && ! $checkOut) {
            return;
        }

        $sessions = EmployeeClockSession::query()
            ->where('employee_id', $employee->id)
            ->where(function ($q) use ($date) {
                $q->whereDate('clock_in_at', $date)
                    ->orWhereDate('clock_out_at', $date);
            })
            ->orderBy('clock_in_at')
            ->get();
        if ($sessions->isEmpty()) {
            return;
        }

        $first = $sessions->first();
        $last = $sessions->last();
        if ($checkIn) {
            $first->clock_in_at = Carbon::parse($date.' '.$checkIn, AppTimezone::name());
            $first->save();
        }
        if ($checkOut) {
            $out = Carbon::parse($date.' '.$checkOut, AppTimezone::name());
            $in = AppTimezone::normalize($last->clock_in_at) ?? Carbon::parse($last->clock_in_at);
            if ($out->lte($in)) {
                $out->addDay();
            }
            $last->clock_out_at = $out;
            $last->clock_out_kind = EmployeeClockSession::CLOCK_OUT_KIND_HR;
            $last->needs_reconciliation = false;
            $last->save();
        }

        $this->reconciler->reconcileFromSessions(
            $employee,
            $date,
            (string) ($first->source ?: 'clock_device'),
            $first->device_identifier,
            $first->branch_id ? (int) $first->branch_id : null,
        );
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

        $employee = \App\Services\Attendance\Hikvision\HikvisionService::findUniqueEmployeeForTerminalNo($orgId, $code);
        if ($employee) {
            return $employee->loadMissing('shift');
        }

        throw ValidationException::withMessages([
            'employee_code' => "No Centrix employee matches terminal ID \"{$code}\". Map this ID on Attendance clock-in, or enroll the same employee number on the device.",
        ]);
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
        string $source = 'clock_device',
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

        if ($source !== 'hr_applied') {
            try {
                $this->dayPolicy->assertCanClockIn($employee, $punchedAt->toIso8601String());
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    'employee_id' => $e->getMessage(),
                ]);
            }
        }

        $session = EmployeeClockSession::create([
            'employee_id' => $employee->id,
            'organization_id' => $employee->organization_id,
            'branch_id' => $branchId ?? $employee->branch_id,
            'source' => $source,
            'clock_in_at' => $punchedAt,
            'device_identifier' => $deviceNo,
        ]);

        $attendance = $this->reconciler->recordOpenClockIn(
            $employee,
            $punchedAt,
            $source,
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
        string $source = 'clock_device',
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
        $open->clock_out_kind = EmployeeClockSession::CLOCK_OUT_KIND_DEVICE;
        $open->needs_reconciliation = false;
        if ($source === 'hr_applied') {
            $open->source = 'hr_applied';
        }
        if ($deviceNo) {
            $open->device_identifier = $deviceNo;
        }
        $open->save();

        $attendanceDate = AppTimezone::normalize($open->clock_in_at)?->toDateString()
            ?? $punchedAt->timezone(AppTimezone::name())->toDateString();
        $attendance = $this->reconciler->reconcileFromSessions(
            $employee,
            $attendanceDate,
            $source,
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
