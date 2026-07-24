<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeClockSession;
use App\Models\EmployeeOvertime;
use App\Models\OrganizationHoliday;
use App\Models\WorkShift;
use App\Services\Hr\HrPayrollSettingsResolver;
use App\Services\Payroll\OvertimeRateCalculator;
use Carbon\Carbon;

class AttendanceDayReconciler
{
    public const AUTO_OT_NOTE_PREFIX = 'auto_from_attendance';

    /** Minimum overtime hours before a pending OT draft is created. */
    public const MIN_AUTO_OVERTIME_HOURS = 1.0;

    public function __construct(
        protected AttendanceDayPolicy $dayPolicy,
        protected LeaveRequestCalculator $leaveCalculator,
        protected OvertimeRateCalculator $overtimeRates,
    ) {}

    /**
     * Rebuild attendance (and optional pending OT) from closed clock sessions for the date.
     */
    public function reconcileFromSessions(
        Employee $employee,
        string $date,
        string $source = 'clock_device',
        ?string $deviceIdentifier = null,
        ?int $branchId = null,
    ): EmployeeAttendance {
        $employee->loadMissing('shift');
        $sessions = EmployeeClockSession::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('clock_out_at')
            ->where(function ($q) use ($date) {
                $q->whereDate('clock_in_at', $date)
                    ->orWhereDate('clock_out_at', $date);
            })
            ->orderBy('clock_in_at')
            ->get();

        $pairs = [];
        foreach ($sessions as $session) {
            $pairs[] = [
                'in' => Carbon::parse($session->clock_in_at),
                'out' => Carbon::parse($session->clock_out_at),
            ];
        }

        $attendance = $this->applyComputation(
            $employee,
            $date,
            $pairs,
            $source,
            $deviceIdentifier,
            $branchId ?? $employee->branch_id,
        );

        foreach ($sessions as $session) {
            if ((int) $session->attendance_id !== (int) $attendance->id) {
                $session->attendance_id = $attendance->id;
                $session->save();
            }
        }

        return $attendance;
    }

    /**
     * Manual single check-in / check-out span (treated as one work segment; lunch skipped if required).
     */
    public function reconcileManualSpan(
        Employee $employee,
        string $date,
        ?string $checkIn,
        ?string $checkOut,
        string $source = 'manual',
        ?string $deviceIdentifier = null,
        ?int $branchId = null,
        ?string $notes = null,
        ?string $forcedStatus = null,
    ): EmployeeAttendance {
        $pairs = [];
        if ($checkIn && $checkOut) {
            $in = Carbon::parse($date.' '.$checkIn);
            $out = Carbon::parse($date.' '.$checkOut);
            if ($out->lte($in)) {
                $out->addDay();
            }
            $pairs[] = ['in' => $in, 'out' => $out];
        }

        return $this->applyComputation(
            $employee,
            $date,
            $pairs,
            $source,
            $deviceIdentifier,
            $branchId ?? $employee->branch_id,
            $notes,
            $forcedStatus,
        );
    }

    /**
     * Expected paid hours for a scheduled workday.
     * When lunch is paid (org default), expected = full shift span.
     * When unpaid, expected = span − lunch minutes.
     */
    public function expectedPaidHours(Employee $employee, string $date): float
    {
        $shift = $employee->relationLoaded('shift') ? $employee->shift : ($employee->shift_id ? WorkShift::find($employee->shift_id) : null);
        if (! $shift) {
            return LeaveRequestCalculator::DEFAULT_SHIFT_HOURS;
        }

        $isHoliday = OrganizationHoliday::query()
            ->where('organization_id', $employee->organization_id)
            ->where('is_active', true)
            ->whereDate('holiday_date', $date)
            ->exists();

        $hours = $shift->hoursForDate($date, $isHoliday);
        $span = $this->leaveCalculator->hoursBetweenTimes(
            $hours['start_time'],
            $hours['end_time'],
            (bool) $hours['crosses_midnight'],
        );

        $hr = HrPayrollSettingsResolver::forOrganizationId((int) $employee->organization_id);
        $lunchIsPaid = (bool) ($hr['lunch_break_is_paid'] ?? true);
        if ($lunchIsPaid) {
            return round(max(0, $span), 2);
        }

        $lunchMinutes = (bool) ($hours['lunch_required'] ?? true)
            ? (int) ($hours['lunch_minutes'] ?? 0)
            : 0;

        return round(max(0, $span - ($lunchMinutes / 60)), 2);
    }

    /**
     * @param  list<array{in: Carbon, out: Carbon}>  $pairs
     */
    protected function applyComputation(
        Employee $employee,
        string $date,
        array $pairs,
        string $source,
        ?string $deviceIdentifier,
        ?int $branchId,
        ?string $notes = null,
        ?string $forcedStatus = null,
    ): EmployeeAttendance {
        $employee->loadMissing('shift');
        $eval = $this->dayPolicy->evaluate($employee, $date);

        if (! $eval['should_work'] && in_array($forcedStatus ?? '', ['leave', 'holiday', 'absent'], true) === false) {
            $status = $forcedStatus ?? $eval['suggested_status'];
            $attendance = EmployeeAttendance::query()->updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'attendance_date' => $date,
                ],
                [
                    'organization_id' => $employee->organization_id,
                    'branch_id' => $branchId ?? $employee->branch_id,
                    'check_in' => null,
                    'check_out' => null,
                    'status' => $status,
                    'source' => $source,
                    'device_identifier' => $deviceIdentifier,
                    'hours_worked' => 0,
                    'expected_hours' => 0,
                    'late_minutes' => 0,
                    'lunch_status' => '-',
                    'lunch_minutes' => null,
                    'early_leave_minutes' => 0,
                    'overtime_minutes' => 0,
                    'notes' => $notes ?? $eval['reason'],
                ],
            );
            $this->clearAutoOvertime($employee->id, $date);

            return $attendance;
        }

        if (in_array($forcedStatus ?? '', ['leave', 'holiday', 'absent'], true)) {
            $attendance = EmployeeAttendance::query()->updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'attendance_date' => $date,
                ],
                [
                    'organization_id' => $employee->organization_id,
                    'branch_id' => $branchId ?? $employee->branch_id,
                    'check_in' => null,
                    'check_out' => null,
                    'status' => $forcedStatus,
                    'source' => $source,
                    'device_identifier' => $deviceIdentifier,
                    'hours_worked' => 0,
                    'expected_hours' => 0,
                    'late_minutes' => 0,
                    'lunch_status' => '-',
                    'lunch_minutes' => null,
                    'early_leave_minutes' => 0,
                    'overtime_minutes' => 0,
                    'notes' => $notes,
                ],
            );
            $this->clearAutoOvertime($employee->id, $date);

            return $attendance;
        }

        $shift = $employee->shift;
        $isHoliday = (bool) ($eval['is_holiday'] ?? false);
        $shiftHours = $shift
            ? $shift->hoursForDate($date, $isHoliday)
            : [
                'start_time' => '08:00:00',
                'end_time' => '17:00:00',
                'crosses_midnight' => false,
                'lunch_minutes' => 60,
                'lunch_required' => true,
            ];

        $shiftStart = Carbon::parse($date.' '.$this->normalizeTime($shiftHours['start_time'] ?? '08:00:00'));
        $shiftEnd = Carbon::parse($date.' '.$this->normalizeTime($shiftHours['end_time'] ?? '17:00:00'));
        if (! empty($shiftHours['crosses_midnight']) || $shiftEnd->lte($shiftStart)) {
            $shiftEnd->addDay();
        }

        $lunchRequired = (bool) ($shiftHours['lunch_required'] ?? true);
        $configuredLunch = max(0, (int) ($shiftHours['lunch_minutes'] ?? 0));
        $bankLunch = (bool) ($employee->bank_lunch_as_work ?? false);

        $expectedHours = $this->expectedPaidHours($employee, $date);
        $allowedEnd = $shiftEnd->copy();
        // Bank lunch + skipped lunch (single segment): early leave by lunch length is OK.
        // Applied after we know lunch was skipped.

        if ($pairs === []) {
            $attendance = EmployeeAttendance::query()->updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'attendance_date' => $date,
                ],
                [
                    'organization_id' => $employee->organization_id,
                    'branch_id' => $branchId ?? $employee->branch_id,
                    'check_in' => null,
                    'check_out' => null,
                    'status' => $forcedStatus ?? 'absent',
                    'source' => $source,
                    'device_identifier' => $deviceIdentifier,
                    'hours_worked' => 0,
                    'expected_hours' => $expectedHours,
                    'late_minutes' => 0,
                    'lunch_status' => $lunchRequired ? 'skipped' : '-',
                    'lunch_minutes' => null,
                    'early_leave_minutes' => 0,
                    'overtime_minutes' => 0,
                    'notes' => $notes,
                ],
            );
            $this->clearAutoOvertime($employee->id, $date);

            return $attendance;
        }

        usort($pairs, fn ($a, $b) => $a['in']->timestamp <=> $b['in']->timestamp);

        $firstIn = $pairs[0]['in']->copy();
        $lastOut = $pairs[array_key_last($pairs)]['out']->copy();

        $lateMinutes = 0;
        if ($firstIn->gt($shiftStart)) {
            $lateMinutes = (int) max(0, (int) floor(($firstIn->getTimestamp() - $shiftStart->getTimestamp()) / 60));
        }

        $actualLunchMinutes = null;
        $lunchStatus = '-';
        if (! $lunchRequired) {
            $lunchStatus = '-';
        } elseif (count($pairs) >= 2) {
            $gapStart = $pairs[0]['out'];
            $gapEnd = $pairs[1]['in'];
            $actualLunchMinutes = (int) max(0, (int) floor(($gapEnd->getTimestamp() - $gapStart->getTimestamp()) / 60));
            $lunchStatus = 'taken';
        } else {
            $lunchStatus = 'skipped';
        }

        if ($bankLunch && $lunchStatus === 'skipped' && $configuredLunch > 0) {
            $allowedEnd = $shiftEnd->copy()->subMinutes($configuredLunch);
        }

        $hr = HrPayrollSettingsResolver::forOrganizationId((int) $employee->organization_id);
        $lunchIsPaid = (bool) ($hr['lunch_break_is_paid'] ?? true);

        $workSeconds = 0;
        $overtimeSeconds = 0;
        foreach ($pairs as $pair) {
            $segStart = $pair['in']->copy();
            $segEnd = $pair['out']->copy();
            if ($segEnd->lte($segStart)) {
                continue;
            }

            // Work portion: within [shiftStart, shiftEnd].
            $paidStart = $segStart->greaterThan($shiftStart) ? $segStart->copy() : $shiftStart->copy();
            $paidEnd = $segEnd->lessThan($shiftEnd) ? $segEnd->copy() : $shiftEnd->copy();
            if ($paidEnd->gt($paidStart)) {
                $workSeconds += max(0, $paidEnd->getTimestamp() - $paidStart->getTimestamp());
            }

            // Overtime: work after scheduled shift end.
            if ($segEnd->gt($shiftEnd)) {
                $otStart = $segStart->greaterThan($shiftEnd) ? $segStart->copy() : $shiftEnd->copy();
                $overtimeSeconds += max(0, $segEnd->getTimestamp() - $otStart->getTimestamp());
            }
        }

        // Paid lunch: credit configured lunch when taken (or banked when skipped).
        // Working through lunch already sits in workSeconds, so no extra credit then.
        $lunchCreditSeconds = 0;
        if ($lunchIsPaid && $lunchRequired && $configuredLunch > 0) {
            if ($lunchStatus === 'taken') {
                $creditMinutes = min($actualLunchMinutes ?? $configuredLunch, $configuredLunch);
                $lunchCreditSeconds = $creditMinutes * 60;
            } elseif ($lunchStatus === 'skipped' && $bankLunch) {
                $lunchCreditSeconds = $configuredLunch * 60;
            }
        }

        $paidHours = round(($workSeconds + $lunchCreditSeconds) / 3600, 2);
        // Cap at expected paid hours (working through lunch does not add extra basic pay).
        if ($paidHours > $expectedHours) {
            $paidHours = $expectedHours;
        }

        $earlyLeaveMinutes = 0;
        if ($lastOut->lt($allowedEnd)) {
            $earlyLeaveMinutes = (int) max(0, (int) floor(($allowedEnd->getTimestamp() - $lastOut->getTimestamp()) / 60));
        }

        // Unpaid-lunch mode only: skipped lunch without banking — deduct early leave from paid hours.
        if (! $lunchIsPaid && $lunchStatus === 'skipped' && ! $bankLunch && $earlyLeaveMinutes > 0) {
            $paidHours = round(max(0, $paidHours - ($earlyLeaveMinutes / 60)), 2);
        }

        $overtimeMinutes = (int) floor($overtimeSeconds / 60);
        $status = $forcedStatus;
        if ($status === null || $status === 'present' || $status === 'late') {
            if ($lateMinutes > 0) {
                $status = 'late';
            } elseif ($expectedHours > 0 && $paidHours < ($expectedHours * 0.5)) {
                $status = 'half_day';
            } else {
                $status = 'present';
            }
        }

        $existing = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date)
            ->first();

        $latenessWaived = (bool) ($existing?->lateness_waived);
        $waiverReason = $existing?->lateness_waiver_reason;
        $waivedBy = $existing?->lateness_waived_by;
        $waivedAt = $existing?->lateness_waived_at;

        // Waived lateness is restored into paid hours so payroll is not reduced.
        if ($latenessWaived && $lateMinutes > 0) {
            $paidHours = round(min(
                $expectedHours > 0 ? $expectedHours : ($paidHours + ($lateMinutes / 60)),
                $paidHours + ($lateMinutes / 60),
            ), 2);
            if ($status === 'late') {
                $status = 'present';
            }
        }

        $attendance = EmployeeAttendance::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'attendance_date' => $date,
            ],
            [
                'organization_id' => $employee->organization_id,
                'branch_id' => $branchId ?? $employee->branch_id,
                'check_in' => $firstIn->format('H:i:s'),
                'check_out' => $lastOut->format('H:i:s'),
                'status' => $status,
                'source' => $source,
                'device_identifier' => $deviceIdentifier,
                'hours_worked' => $paidHours,
                'expected_hours' => $expectedHours,
                'late_minutes' => $lateMinutes,
                'lateness_waived' => $latenessWaived,
                'lateness_waiver_reason' => $latenessWaived ? $waiverReason : null,
                'lateness_waived_by' => $latenessWaived ? $waivedBy : null,
                'lateness_waived_at' => $latenessWaived ? $waivedAt : null,
                'lunch_status' => $lunchStatus,
                'lunch_minutes' => $actualLunchMinutes,
                'early_leave_minutes' => $earlyLeaveMinutes,
                'overtime_minutes' => $overtimeMinutes,
                'notes' => $notes,
            ],
        );

        $this->syncAutoOvertime($employee, $date, $overtimeMinutes);

        return $attendance;
    }

    /**
     * Toggle lateness waiver and adjust paid hours for payroll.
     */
    public function setLatenessWaiver(
        EmployeeAttendance $attendance,
        bool $waived,
        ?string $reason = null,
        ?int $userId = null,
    ): EmployeeAttendance {
        $late = (int) ($attendance->late_minutes ?? 0);
        if ($waived && $late <= 0) {
            throw new \InvalidArgumentException('No lateness to waive on this attendance record.');
        }

        $wasWaived = (bool) $attendance->lateness_waived;
        $expected = (float) ($attendance->expected_hours ?? 0);
        $hours = (float) ($attendance->hours_worked ?? 0);

        if ($waived && ! $wasWaived) {
            $hours = round(min(
                $expected > 0 ? $expected : ($hours + ($late / 60)),
                $hours + ($late / 60),
            ), 2);
            if ($attendance->status === 'late') {
                $attendance->status = 'present';
            }
        } elseif (! $waived && $wasWaived) {
            $hours = round(max(0, $hours - ($late / 60)), 2);
            if ($late > 0 && in_array($attendance->status, ['present', 'late'], true)) {
                $attendance->status = 'late';
            }
        }

        $attendance->fill([
            'hours_worked' => $hours,
            'lateness_waived' => $waived,
            'lateness_waiver_reason' => $waived ? ($reason ?: $attendance->lateness_waiver_reason) : null,
            'lateness_waived_by' => $waived ? ($userId ?? $attendance->lateness_waived_by) : null,
            'lateness_waived_at' => $waived ? ($attendance->lateness_waived_at ?? now()) : null,
        ]);
        $attendance->save();

        return $attendance->fresh(['employee', 'branch']);
    }

    protected function syncAutoOvertime(Employee $employee, string $date, int $overtimeMinutes): void
    {
        $hours = round($overtimeMinutes / 60, 2);
        $existing = EmployeeOvertime::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->where('notes', 'like', self::AUTO_OT_NOTE_PREFIX.'%')
            ->first();

        if ($hours < self::MIN_AUTO_OVERTIME_HOURS) {
            if ($existing && $existing->status === 'pending' && $existing->payroll_run_id === null) {
                $existing->delete();
            }

            return;
        }

        if ($existing && in_array($existing->status, ['approved', 'paid', 'rejected'], true)) {
            return;
        }

        $hr = HrPayrollSettingsResolver::forOrganizationId((int) $employee->organization_id);
        $mult = max(1, (float) ($hr['overtime_rate_multiplier'] ?? 1.5));
        $rate = $this->overtimeRates->hourlyFromSalary($employee, $date);
        $amount = round($hours * $rate * $mult, 2);

        $payload = [
            'employee_id' => $employee->id,
            'organization_id' => $employee->organization_id,
            'branch_id' => $employee->branch_id,
            'work_date' => $date,
            'hours' => $hours,
            'rate_mode' => 'from_salary',
            'hourly_rate' => $rate,
            'rate_multiplier' => $mult,
            'amount' => $amount,
            'status' => 'pending',
            'notes' => self::AUTO_OT_NOTE_PREFIX.': '.$hours.'h past shift end',
        ];

        if ($existing) {
            if ($existing->payroll_run_id !== null) {
                return;
            }
            $existing->update($payload);
        } else {
            EmployeeOvertime::create($payload);
        }
    }

    protected function clearAutoOvertime(int $employeeId, string $date): void
    {
        EmployeeOvertime::query()
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $date)
            ->where('status', 'pending')
            ->whereNull('payroll_run_id')
            ->where('notes', 'like', self::AUTO_OT_NOTE_PREFIX.'%')
            ->delete();
    }

    protected function normalizeTime(?string $time): string
    {
        if (! $time) {
            return '00:00:00';
        }
        $time = trim($time);
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time.':00';
        }

        return $time;
    }
}
