<?php

namespace App\Services\Attendance;

use App\Models\AttendanceClockDevice;
use App\Models\Employee;
use App\Models\EmployeeClockSession;
use App\Support\AppTimezone;
use Carbon\Carbon;
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

        if ($direction === 'auto') {
            $direction = $open ? 'out' : 'in';
        }

        if ($direction === 'in') {
            return $this->clockIn($employee, $punchedAt, $deviceNo, $payload['branch_id'] ?? null, $open);
        }

        return $this->clockOut($employee, $punchedAt, $deviceNo, $open);
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

        $employee = Employee::with('shift')
            ->where('organization_id', $orgId)
            ->where(function ($q) use ($code) {
                $q->where('employee_code', $code)
                    ->orWhere('payroll_number', $code);
            })
            ->first();

        if (! $employee) {
            // Hikvision often stores bare numbers without EMP# prefix.
            $employee = Employee::with('shift')
                ->where('organization_id', $orgId)
                ->where(function ($q) use ($code) {
                    $q->where('employee_code', 'like', '%'.$code)
                        ->orWhere('payroll_number', 'like', '%'.$code);
                })
                ->orderBy('id')
                ->first();
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

        try {
            $at = $raw instanceof Carbon
                ? $raw->copy()
                : Carbon::parse((string) $raw, AppTimezone::name());
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'punched_at' => 'Invalid punched_at timestamp.',
            ]);
        }

        if ($at->isFuture() && $at->diffInMinutes(AppTimezone::now()) > 5) {
            throw ValidationException::withMessages([
                'punched_at' => 'Punch time cannot be in the future.',
            ]);
        }

        return $at;
    }

    /**
     * @return array{action: string, session: EmployeeClockSession}
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
            $this->dayPolicy->assertCanClockIn($employee);
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

        return ['action' => 'in', 'session' => $session->load('employee')];
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

        $attendanceDate = Carbon::parse($open->clock_in_at)->toDateString();
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
