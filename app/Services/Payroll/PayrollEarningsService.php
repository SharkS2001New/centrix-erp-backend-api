<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeaveDay;
use App\Models\EmployeeOvertime;
use App\Models\PayPeriod;
use App\Services\Attendance\AttendanceDayPolicy;
use App\Services\Attendance\AttendanceDayReconciler;
use App\Services\Attendance\LeaveRequestCalculator;
use App\Services\Hr\HrPayrollSettingsResolver;
use Carbon\Carbon;

class PayrollEarningsService
{
    public function __construct(
        protected AttendanceDayPolicy $dayPolicy,
        protected LeaveRequestCalculator $leaveCalculator,
        protected OvertimeRateCalculator $overtimeRates,
        protected AttendanceDayReconciler $attendanceReconciler,
    ) {}

    /**
     * @param  array{
     *   include_allowances?: bool,
     *   include_other_deductions?: bool,
     *   include_overtime?: bool,
     *   use_attendance_proration?: bool
     * }  $options
     * @return array<string, mixed>|null null when employee should be skipped
     */
    public function buildLineInput(Employee $employee, PayPeriod $period, array $options = []): ?array
    {
        if (! $employee->shift_id) {
            return null;
        }

        $contractBasic = (float) $employee->base_salary;
        if ($contractBasic <= 0) {
            return null;
        }

        $includeAllowances = (bool) ($options['include_allowances'] ?? true);
        $includeOther = (bool) ($options['include_other_deductions'] ?? false);
        $includeOvertime = (bool) ($options['include_overtime'] ?? true);
        $useProration = (bool) ($options['use_attendance_proration'] ?? true);
        $hr = HrPayrollSettingsResolver::forOrganizationId((int) $employee->organization_id);
        if ($hr['require_attendance_for_payroll']) {
            $useProration = true;
        }

        $start = $period->period_start->format('Y-m-d');
        $end = $period->period_end->format('Y-m-d');

        $attendanceSummary = $useProration
            ? $this->summarizeAttendance($employee, $start, $end)
            : null;

        if ($hr['require_attendance_for_payroll'] && $attendanceSummary) {
            $paidHours = (float) ($attendanceSummary['paid_hours'] ?? 0);
            $paidLeaveHours = (float) ($attendanceSummary['paid_leave_hours'] ?? 0);
            if ($paidHours <= 0 && $paidLeaveHours <= 0) {
                $attended = (float) ($attendanceSummary['attended_days'] ?? 0);
                $paidLeave = (float) ($attendanceSummary['paid_leave_days'] ?? 0);
                if ($attended <= 0 && $paidLeave <= 0) {
                    return null;
                }
            }
        }

        $expectedHours = (float) ($attendanceSummary['expected_hours'] ?? 0);
        $expectedDays = $attendanceSummary['expected_days'] ?? $this->expectedWorkDays($employee, $start, $end);
        if ($expectedDays <= 0 && $expectedHours <= 0) {
            return null;
        }

        $remainingDays = (float) ($attendanceSummary['remaining_days'] ?? 0);
        $paidHours = $useProration
            ? (float) ($attendanceSummary['paid_hours'] ?? $expectedHours)
            : $expectedHours;

        if ($useProration && $expectedDays > 0) {
            // Payable days: scheduled shift workdays attended or on paid leave through yesterday.
            // Off days are not paid and are not absences. Today is still open.
            $paidDays = round((float) ($attendanceSummary['paid_days'] ?? 0), 2);
            $monthDaysBasis = $this->payrollMonthDaysBasis((int) $employee->organization_id);
            if ($monthDaysBasis === 'fixed_30') {
                $absentDays = (float) ($attendanceSummary['absent_days'] ?? 0);
                $ratio = max(0, (30 - $absentDays) / 30);
            } else {
                $ratio = $paidDays / $expectedDays;
            }
            // Lateness / short hours on an otherwise paid day reduce salary by the hour shortfall.
            $lateMinutes = (int) ($attendanceSummary['late_minutes_total'] ?? 0);
            if ($lateMinutes > 0 && $expectedHours > 0) {
                $ratio = max(0, $ratio - (($lateMinutes / 60) / $expectedHours));
            }
            $periodBasic = round($contractBasic * $ratio, 2);
            $dailyRate = round($contractBasic / $this->monthDayDivisor($monthDaysBasis, $expectedDays), 2);
        } else {
            $periodBasic = $contractBasic;
            $paidDays = $expectedDays;
            $monthDaysBasis = $this->payrollMonthDaysBasis((int) $employee->organization_id);
            $dailyRate = $expectedDays > 0
                ? round($contractBasic / $this->monthDayDivisor($monthDaysBasis, $expectedDays), 2)
                : 0.0;
            $ratio = 1.0;
        }

        $allowanceBreakdown = $this->resolveAllowances(
            $employee,
            $contractBasic,
            $paidDays,
            $expectedDays,
            $includeAllowances,
            $useProration,
            $ratio,
        );
        $allowances = $allowanceBreakdown['period'];
        $overtimeTotal = $includeOvertime
            ? $this->approvedOvertimeInPeriod($employee->id, $start, $end)
            : 0.0;

        $grossBeforeOther = round($periodBasic + $allowances + $overtimeTotal, 2);
        $contractGrossForOther = round($contractBasic + $allowanceBreakdown['monthly'], 2);
        $other = 0.0;
        $deductionsDetail = [];

        if ($includeOther) {
            $built = app(PayrollOtherDeductionsBuilder::class)->build($employee, $contractGrossForOther, $hr);
            $other = $built['total'];
            $deductionsDetail = $built['detail'];
        }

        return [
            'employee_id' => $employee->id,
            'basic_salary' => $contractBasic,
            'allowances' => $allowances,
            'gross_pay' => $grossBeforeOther,
            'other_deductions' => round($other, 2),
            'payroll_meta' => [
                'contract_monthly_salary' => $contractBasic,
                'period_basic' => $periodBasic,
                'monthly_allowance' => $allowanceBreakdown['monthly'],
                'contract_gross_for_statutory' => $contractGrossForOther,
                'allowance_source' => $allowanceBreakdown['source'],
                'allowance_lines' => $allowanceBreakdown['lines'],
                'allowances_period' => $allowances,
                'expected_work_days' => $expectedDays,
                'paid_work_days' => $useProration ? round($paidDays, 2) : $expectedDays,
                'calendar_paid_days' => $useProration ? round($paidDays, 2) : $expectedDays,
                'remaining_days' => $useProration ? round($remainingDays, 2) : 0.0,
                'expected_hours' => round($expectedHours, 2),
                'paid_hours' => round($paidHours, 2),
                'hour_ratio' => round($ratio, 4),
                'daily_rate' => $dailyRate,
                'overtime' => $overtimeTotal,
                'attendance' => $attendanceSummary,
                'deductions_detail' => $deductionsDetail,
                'other_deductions_percent_base' => $contractGrossForOther,
                'other_deductions_not_prorated' => true,
                'use_attendance_proration' => $useProration,
            ],
        ];
    }

    /**
     * @return array{
     *   expected_days: float,
     *   paid_days: float,
     *   remaining_days: float,
     *   rest_days_paid: float,
     *   rest_days_off: float,
     *   attended_days: float,
     *   assumed_future_days: float,
     *   paid_leave_days: float,
     *   unpaid_leave_days: float,
     *   absent_days: float,
     *   expected_hours: float,
     *   paid_hours: float,
     *   paid_leave_hours: float,
     *   late_minutes_total: int,
     *   clock_in_late_minutes_total: int,
     *   lunch_late_minutes_total: int
     * }
     */
    public function summarizeAttendance(Employee $employee, string $start, string $end): array
    {
        $employee->loadMissing('shift');

        $leaves = EmployeeLeaveDay::query()
            ->where('employee_id', $employee->id)
            ->whereDate('end_date', '>=', $start)
            ->whereDate('start_date', '<=', $end)
            ->whereNull('payroll_run_id')
            ->where(function ($q) {
                $q->where('approval_status', 'approved')
                    ->orWhereNull('approval_status');
            })
            ->get();

        $attendanceByDate = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $start)
            ->whereDate('attendance_date', '<=', $end)
            ->whereNull('payroll_run_id')
            ->get()
            ->keyBy(fn ($a) => $a->attendance_date->format('Y-m-d'));

        $expected = 0.0;
        $attended = 0.0;
        $remaining = 0.0;
        $restDaysOff = 0.0;
        $paidLeave = 0.0;
        $unpaidLeave = 0.0;
        $deductibleOffDays = 0.0;
        $nonDeductibleOffDays = 0.0;
        $expectedHours = 0.0;
        $paidHours = 0.0;
        $paidLeaveHours = 0.0;
        $lateMinutesTotal = 0;
        $clockInLateMinutesTotal = 0;
        $lunchLateMinutesTotal = 0;

        $cursor = Carbon::parse($start)->startOfDay();
        $endDay = Carbon::parse($end)->startOfDay();
        $today = Carbon::today();

        while ($cursor->lte($endDay)) {
            $date = $cursor->toDateString();
            $isWorkday = $this->dayPolicy->isScheduledWorkday($employee, $date);
            $dayExpectedHours = $isWorkday
                ? $this->attendanceReconciler->expectedPaidHours($employee, $date)
                : 0.0;

            // Off days (not on the shift / employee work week) are not paid and not absences.
            if (! $isWorkday) {
                $restDaysOff += 1.0;
                $cursor->addDay();

                continue;
            }

            $expected += 1.0;
            $expectedHours += $dayExpectedHours;

            // Today has not ended, and later scheduled days are not paid yet.
            if ($cursor->gte($today)) {
                $remaining += 1.0;
                $cursor->addDay();

                continue;
            }

            $dayFraction = 1.0;
            $leave = $leaves->first(fn (EmployeeLeaveDay $l) => $l->coversDate($date));
            if ($leave) {
                $dayFraction = $leave->duration_type === 'half_day' ? 0.5 : 1.0;
                $leaveHours = round($dayExpectedHours * $dayFraction, 2);
                $isOff = ($leave->assignment_kind ?? 'leave') === 'off_day';
                if ($this->leaveIsUnpaid($leave)) {
                    $unpaidLeave += $dayFraction;
                    if ($isOff) {
                        $deductibleOffDays += $dayFraction;
                    }
                } else {
                    $paidLeave += $dayFraction;
                    $paidHours += $leaveHours;
                    $paidLeaveHours += $leaveHours;
                    if ($isOff) {
                        $nonDeductibleOffDays += $dayFraction;
                    }
                }
                $cursor->addDay();

                continue;
            }

            $att = $attendanceByDate->get($date);
            if ($att && $this->attendanceCountsAsPaid($att->status)) {
                $attended += $att->status === 'half_day' ? 0.5 : 1.0;
                $dayPaid = (float) ($att->hours_worked ?? 0);
                if ($att->expected_hours !== null && (float) $att->expected_hours > 0) {
                    $dayPaid = min($dayPaid, (float) $att->expected_hours);
                } else {
                    $dayPaid = min($dayPaid, $dayExpectedHours);
                }
                $paidHours += $dayPaid;
                $clockInLate = (int) ($att->late_minutes ?? 0);
                $lunchLate = (int) ($att->lunch_late_minutes ?? 0);
                if (! (bool) ($att->lateness_waived ?? false)) {
                    $clockInLateMinutesTotal += $clockInLate;
                    $lunchLateMinutesTotal += $lunchLate;
                    $lateMinutesTotal += $clockInLate + $lunchLate;
                }
            }

            $cursor->addDay();
        }

        // Paid scheduled workdays through yesterday. Off days are excluded from expected and absent.
        $paidDays = round($attended + $paidLeave, 2);
        $scheduledDays = round($expected, 2);
        $calendarDays = (float) (Carbon::parse($start)->startOfDay()->diffInDays(Carbon::parse($end)->startOfDay()) + 1);
        $expectedForPay = $this->payrollMonthDaysBasis((int) $employee->organization_id) === 'fixed_30'
            ? 30.0
            : $scheduledDays;
        $absent = round(max(0, $scheduledDays - $paidDays - $unpaidLeave - $remaining), 2);

        return [
            'expected_days' => $expectedForPay,
            'calendar_days_in_period' => $calendarDays,
            'paid_days' => $paidDays,
            'remaining_days' => round($remaining, 2),
            'rest_days_paid' => 0.0,
            'rest_days_off' => round($restDaysOff, 2),
            'attended_days' => round($attended, 2),
            'assumed_future_days' => round($remaining, 2),
            'paid_leave_days' => round($paidLeave, 2),
            'unpaid_leave_days' => round($unpaidLeave, 2),
            'deductible_off_days' => round($deductibleOffDays, 2),
            'non_deductible_off_days' => round($nonDeductibleOffDays, 2),
            'absent_days' => $absent,
            'expected_hours' => round($expectedHours, 2),
            'paid_hours' => round($paidHours, 2),
            'paid_leave_hours' => round($paidLeaveHours, 2),
            'late_minutes_total' => $lateMinutesTotal,
            'clock_in_late_minutes_total' => $clockInLateMinutesTotal,
            'lunch_late_minutes_total' => $lunchLateMinutesTotal,
        ];
    }

    public function expectedWorkDays(Employee $employee, string $start, string $end): float
    {
        if ($this->payrollMonthDaysBasis((int) $employee->organization_id) === 'fixed_30') {
            return 30.0;
        }

        $count = 0.0;
        $cursor = Carbon::parse($start)->startOfDay();
        $endDay = Carbon::parse($end)->startOfDay();
        while ($cursor->lte($endDay)) {
            if ($this->dayPolicy->isScheduledWorkday($employee, $cursor->toDateString())) {
                $count += 1.0;
            }
            $cursor->addDay();
        }

        return $count;
    }

    protected function payrollMonthDaysBasis(int $organizationId): string
    {
        $hr = HrPayrollSettingsResolver::forOrganizationId($organizationId);

        return ($hr['payroll_month_days_basis'] ?? 'calendar') === 'fixed_30' ? 'fixed_30' : 'calendar';
    }

    protected function monthDayDivisor(string $basis, float $scheduledExpectedDays): float
    {
        if ($basis === 'fixed_30') {
            return 30.0;
        }

        return max(1.0, $scheduledExpectedDays);
    }

    /**
     * @return array{period: float, monthly: float, source: string}
     */
    public function resolveAllowances(
        Employee $employee,
        float $contractBasic,
        float $paidDays,
        float $expectedDays,
        bool $includeAllowances,
        bool $useProration,
        float $hourRatio = 1.0,
    ): array {
        if (! $includeAllowances) {
            return [
                'period' => 0.0,
                'monthly' => 0.0,
                'source' => 'none',
                'lines' => [],
            ];
        }

        $lines = EmployeeAllowance::activeLines($employee->id);
        $monthly = array_sum(array_column($lines, 'amount'));
        $source = 'allowances_module';

        if ($monthly <= 0) {
            $monthly = (float) ($employee->monthly_allowance ?? 0);
            $source = 'employee_field';
            if ($monthly > 0) {
                $lines = [['id' => null, 'name' => 'Monthly allowance', 'amount' => $monthly]];
            } else {
                $source = 'none';
                $lines = [];
            }
        }

        if ($useProration) {
            $ratio = $hourRatio > 0 ? $hourRatio : (($expectedDays > 0) ? ($paidDays / $expectedDays) : 0);
            $periodLines = array_map(fn (array $line) => [
                'id' => $line['id'],
                'name' => $line['name'],
                'amount' => round($line['amount'] * $ratio, 2),
            ], $lines);
            $period = round(array_sum(array_column($periodLines, 'amount')), 2);
        } else {
            $periodLines = $lines;
            $period = round($monthly, 2);
        }

        return [
            'period' => $period,
            'monthly' => round($monthly, 2),
            'source' => $source,
            'lines' => $periodLines,
        ];
    }

    public function approvedOvertimeInPeriod(int $employeeId, string $start, string $end): float
    {
        return round((float) EmployeeOvertime::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['approved', 'pending'])
            ->whereNull('pay_period_id')
            ->whereNull('payroll_run_id')
            ->whereDate('work_date', '>=', $start)
            ->whereDate('work_date', '<=', $end)
            ->sum('amount'), 2);
    }

    /**
     * Deductible offs (and unpaid leave) reduce paid days under attendance proration.
     * Non-deductible offs keep pay (counted as paid leave).
     */
    protected function leaveIsUnpaid(EmployeeLeaveDay $leave): bool
    {
        return $leave->deduct_from === 'unpaid' || $leave->leave_type === 'unpaid';
    }

    protected function attendanceCountsAsPaid(?string $status): bool
    {
        return in_array($status, ['present', 'late', 'half_day'], true);
    }
}
