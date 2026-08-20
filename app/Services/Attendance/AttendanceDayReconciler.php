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
use App\Support\AppTimezone;
use Carbon\Carbon;

class AttendanceDayReconciler
{
    public const AUTO_OT_NOTE_PREFIX = 'auto_from_attendance';

    /** Any clock-out past shift end is logged as pending overtime. */
    public const MIN_AUTO_OVERTIME_HOURS = 0.0;

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
                'in' => AppTimezone::normalize($session->clock_in_at) ?? Carbon::parse($session->clock_in_at),
                'out' => AppTimezone::normalize($session->clock_out_at) ?? Carbon::parse($session->clock_out_at),
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
     * Create / refresh today's HR attendance as soon as the employee clocks in
     * (before clock-out). Closed days with both times are left untouched.
     */
    public function recordOpenClockIn(
        Employee $employee,
        Carbon $clockInAt,
        string $source = 'clock_device',
        ?string $deviceIdentifier = null,
        ?int $branchId = null,
        bool $force = false,
    ): EmployeeAttendance {
        $employee->loadMissing('shift');
        $at = $clockInAt->copy()->timezone(AppTimezone::name());
        $date = $at->toDateString();

        $existing = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date)
            ->first();

        if (! $force && $existing && $existing->check_in && $existing->check_out) {
            return $existing;
        }

        $eval = $this->dayPolicy->evaluate($employee, $date);
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
        $firstIn = $at;
        if ($existing?->check_in) {
            $prev = Carbon::parse($date.' '.$this->normalizeTime((string) $existing->check_in), AppTimezone::name());
            if ($prev->lt($firstIn)) {
                $firstIn = $prev;
            }
        }
        $lateAt = app(AttendancePunchWindowResolver::class)->lateThreshold($employee, $date, $shiftStart);
        $lateMinutes = 0;
        if ($firstIn->gt($lateAt)) {
            $lateMinutes = (int) max(0, (int) floor(($firstIn->getTimestamp() - $lateAt->getTimestamp()) / 60));
        }

        $status = $lateMinutes > 0 ? 'late' : 'present';
        $expectedHours = $this->expectedPaidHours($employee, $date);

        $attendance = EmployeeAttendance::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'attendance_date' => $date,
            ],
            [
                'organization_id' => $employee->organization_id,
                'branch_id' => $branchId ?? $employee->branch_id,
                'check_in' => AppTimezone::clockTime($firstIn),
                'check_out' => $existing?->check_out,
                'status' => $status,
                'source' => $source,
                'device_identifier' => $deviceIdentifier,
                'hours_worked' => $existing?->hours_worked ?? 0,
                'expected_hours' => $expectedHours,
                'late_minutes' => $lateMinutes,
                'lunch_late_minutes' => 0,
                'lunch_status' => $existing?->lunch_status ?? '-',
                'lunch_minutes' => $existing?->lunch_minutes,
                'early_leave_minutes' => $existing?->early_leave_minutes ?? 0,
                'overtime_minutes' => $existing?->overtime_minutes ?? 0,
                'notes' => $source === 'hr_applied'
                    ? 'Applied by HR from terminal punch'
                    : ($existing?->notes ?: 'On shift — awaiting clock-out'),
            ],
        );

        return $attendance;
    }

    /**
     * Manual single check-in / check-out span.
     *
     * @param  bool|null  $lunchTaken  When true (typical manual entry), treat configured lunch as taken
     *                                 even with one in/out pair. When false, lunch was skipped.
     *                                 Null keeps auto-detect (2+ pairs = taken).
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
        ?bool $lunchTaken = null,
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
            $lunchTaken,
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
        if ($lunchMinutes <= 0) {
            return round(max(0, $span), 2);
        }

        return round(max(0, $span - ($lunchMinutes / 60)), 2);
    }

    /**
     * @param  list<array{in: Carbon, out: Carbon}>  $pairs
     * @param  bool|null  $lunchTakenOverride  Manual lunch flag; null = detect from punch pairs
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
        ?bool $lunchTakenOverride = null,
    ): EmployeeAttendance {
        $employee->loadMissing('shift');
        $eval = $this->dayPolicy->evaluate($employee, $date);
        $forcedWork = in_array($forcedStatus ?? '', ['present', 'late', 'half_day'], true);
        $forcedOff = in_array($forcedStatus ?? '', ['leave', 'holiday', 'absent'], true);
        if ($source === 'hr_applied' && ($notes === null || $notes === '')) {
            $notes = 'Applied by HR from terminal punch';
        }

        // Never auto-mark absent on a day the shift / employee work week says is off.
        $isAutoAbsent = $forcedStatus === 'absent'
            && is_string($notes)
            && str_starts_with($notes, 'Auto-marked absent');
        if ($isAutoAbsent && ! ($eval['should_work'] ?? false)) {
            throw new \InvalidArgumentException(
                $eval['reason'] ?? 'Not a scheduled workday for this employee.',
            );
        }

        // Admin can still record times for a non-scheduled day (present/late/half_day with punches).
        if (! $eval['should_work'] && ! $forcedWork && ! $forcedOff) {
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
                    'lunch_late_minutes' => 0,
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

        // Forced absent on a non-scheduled day is not valid — keep the day unmarked / off.
        if ($forcedOff && ! ($eval['should_work'] ?? false) && $forcedStatus === 'absent') {
            throw new \InvalidArgumentException(
                $eval['reason'] ?? 'Not a scheduled workday for this employee.',
            );
        }

        if ($forcedOff) {
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
                    'lunch_late_minutes' => 0,
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

        $lunchRequired = (bool) ($shiftHours['lunch_required'] ?? true)
            && max(0, (int) ($shiftHours['lunch_minutes'] ?? 0)) > 0;
        $configuredLunch = $lunchRequired
            ? max(0, (int) ($shiftHours['lunch_minutes'] ?? 0))
            : 0;
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
                    'lunch_late_minutes' => 0,
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

        $firstIn = $pairs[0]['in']->copy()->timezone(AppTimezone::name());
        $lastOut = $pairs[array_key_last($pairs)]['out']->copy()->timezone(AppTimezone::name());

        $lateAt = app(AttendancePunchWindowResolver::class)->lateThreshold($employee, $date, $shiftStart);
        $lateMinutes = 0;
        if ($firstIn->gt($lateAt)) {
            $lateMinutes = (int) max(0, (int) floor(($firstIn->getTimestamp() - $lateAt->getTimestamp()) / 60));
        }

        $actualLunchMinutes = null;
        $lunchStatus = '-';
        if (! $lunchRequired) {
            $lunchStatus = '-';
        } elseif ($lunchTakenOverride === false) {
            $lunchStatus = 'skipped';
        } elseif ($lunchTakenOverride === true || count($pairs) >= 2) {
            if (count($pairs) >= 2) {
                $gapStart = $pairs[0]['out'];
                $gapEnd = $pairs[1]['in'];
                $actualLunchMinutes = (int) max(0, (int) floor(($gapEnd->getTimestamp() - $gapStart->getTimestamp()) / 60));
            } else {
                // Manual single span with lunch marked taken: use configured lunch length.
                $actualLunchMinutes = $configuredLunch > 0 ? $configuredLunch : null;
            }
            $lunchStatus = 'taken';
        } else {
            $lunchStatus = 'skipped';
        }

        $lunchLateMinutes = 0;
        if ($lunchStatus === 'taken' && $actualLunchMinutes !== null && $configuredLunch > 0) {
            $lunchLateMinutes = max(0, $actualLunchMinutes - $configuredLunch);
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

        // Single-span manual "lunch taken": remove configured lunch from continuous work so
        // paid credit (below) matches multi-punch behaviour instead of double-counting.
        if (
            $lunchStatus === 'taken'
            && count($pairs) === 1
            && $configuredLunch > 0
            && $lunchTakenOverride === true
        ) {
            $workSeconds = max(0, $workSeconds - ($configuredLunch * 60));
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
        $resolver = app(AttendancePunchWindowResolver::class);
        $halfDayFromLunchOut = $lunchTakenOverride === null
            && $lunchRequired
            && count($pairs) === 1
            && $resolver->isInNamedWindow(
                $employee,
                $lastOut,
                'lunch_clock_out_from',
                'lunch_clock_out_to',
            );

        $status = $forcedStatus;
        if ($status === null || $status === 'present' || $status === 'late') {
            if ($halfDayFromLunchOut) {
                $status = 'half_day';
            } elseif ($lateMinutes > 0 || $lunchLateMinutes > 0) {
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

        // Waived lateness (clock-in + lunch) is restored into paid hours so payroll is not reduced.
        $totalLateMinutes = $lateMinutes + $lunchLateMinutes;
        if ($latenessWaived && $totalLateMinutes > 0) {
            $paidHours = round(min(
                $expectedHours > 0 ? $expectedHours : ($paidHours + ($totalLateMinutes / 60)),
                $paidHours + ($totalLateMinutes / 60),
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
                'check_in' => AppTimezone::clockTime($firstIn),
                'check_out' => AppTimezone::clockTime($lastOut),
                'status' => $status,
                'source' => $source,
                'device_identifier' => $deviceIdentifier,
                'hours_worked' => $paidHours,
                'expected_hours' => $expectedHours,
                'late_minutes' => $lateMinutes,
                'lunch_late_minutes' => $lunchLateMinutes,
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
        $late = $attendance->totalLateMinutes();
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

        if ($overtimeMinutes <= 0 || $hours <= self::MIN_AUTO_OVERTIME_HOURS) {
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

    /**
     * Deny a pending auto overtime: drop the OT row and set the day's last clock-out
     * to the scheduled shift end so attendance no longer shows extra time.
     */
    public function rejectPendingOvertimeAndCapClockOut(EmployeeOvertime $overtime): void
    {
        $employee = Employee::with('shift')->find($overtime->employee_id);
        $date = $overtime->work_date instanceof \DateTimeInterface
            ? Carbon::instance($overtime->work_date)->toDateString()
            : Carbon::parse((string) $overtime->work_date)->toDateString();
        $isAuto = str_starts_with((string) $overtime->notes, self::AUTO_OT_NOTE_PREFIX);

        if ($employee && $isAuto) {
            $eval = $this->dayPolicy->evaluate($employee, $date);
            $hours = $employee->shift
                ? $employee->shift->hoursForDate($date, (bool) ($eval['is_holiday'] ?? false))
                : ['end_time' => '17:00:00'];
            $shiftEnd = Carbon::parse(
                $date.' '.$this->normalizeTime($hours['end_time'] ?? '17:00:00'),
                AppTimezone::name(),
            );

            $session = EmployeeClockSession::query()
                ->where('employee_id', $employee->id)
                ->whereNotNull('clock_out_at')
                ->where(function ($q) use ($date) {
                    $q->whereDate('clock_in_at', $date)
                        ->orWhereDate('clock_out_at', $date);
                })
                ->orderByDesc('clock_out_at')
                ->first();

            if ($session) {
                $out = AppTimezone::normalize($session->clock_out_at)
                    ?? Carbon::parse($session->clock_out_at);
                $out = $out->copy()->timezone(AppTimezone::name());
                if ($out->gt($shiftEnd)) {
                    $session->clock_out_at = $shiftEnd->copy();
                    $session->save();
                }
            }
        }

        if ($overtime->status === 'pending' && $overtime->payroll_run_id === null) {
            $overtime->delete();
        }

        if ($employee && $isAuto) {
            $this->reconcileFromSessions($employee->fresh('shift'), $date);
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
