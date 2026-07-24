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

        $paidHours = $useProration
            ? (float) ($attendanceSummary['paid_hours'] ?? $expectedHours)
            : $expectedHours;

        if ($useProration && $expectedDays > 0) {
            // Calendar-day proration: e.g. mid-month run on the 24th of a 31-day
            // period pays 24/31. Rest days (Sun) count in place; future days stay
            // unpaid until they arrive (and are not treated as absences).
            $paidDaysRaw = (float) ($attendanceSummary['paid_days'] ?? 0);
            $ratio = $paidDaysRaw / $expectedDays;
            $payrollBasic = round($contractBasic * $ratio, 2);
            $paidDays = round($paidDaysRaw, 2);
            $dailyRate = round($contractBasic / $expectedDays, 2);
        } else {
            $payrollBasic = $contractBasic;
            $paidDays = $expectedDays;
            $dailyRate = $expectedDays > 0 ? round($contractBasic / $expectedDays, 2) : 0.0;
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

        $grossBeforeOther = round($payrollBasic + $allowances + $overtimeTotal, 2);
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
            'basic_salary' => $payrollBasic,
            'allowances' => $allowances,
            'gross_pay' => $grossBeforeOther,
            'other_deductions' => round($other, 2),
            'payroll_meta' => [
                'contract_monthly_salary' => $contractBasic,
                'monthly_allowance' => $allowanceBreakdown['monthly'],
                'contract_gross_for_statutory' => $contractGrossForOther,
                'allowance_source' => $allowanceBreakdown['source'],
                'allowance_lines' => $allowanceBreakdown['lines'],
                'allowances_period' => $allowances,
                'expected_work_days' => $expectedDays,
                'paid_work_days' => $paidDays,
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
     * Calendar-day attendance summary for payroll.
     *
     * - expected_days = every calendar day in the pay period (e.g. 31)
     * - paid_days = elapsed days through today that are payable, including rest
     *   days such as Sunday counted “in place”
     * - Future days in the period are remaining (not absent, not paid yet)
     *
     * @return array{
     *   expected_days: float,
     *   paid_days: float,
     *   attended_days: float,
     *   rest_days_paid: float,
     *   remaining_days: float,
     *   paid_leave_days: float,
     *   unpaid_leave_days: float,
     *   absent_days: float,
     *   expected_hours: float,
     *   paid_hours: float,
     *   paid_leave_hours: float,
     *   late_minutes_total: int
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
        $restPaid = 0.0;
        $remaining = 0.0;
        $paidLeave = 0.0;
        $unpaidLeave = 0.0;
        $deductibleOffDays = 0.0;
        $nonDeductibleOffDays = 0.0;
        $absent = 0.0;
        $expectedHours = 0.0;
        $paidHours = 0.0;
        $paidLeaveHours = 0.0;
        $lateMinutesTotal = 0;

        $cursor = Carbon::parse($start)->startOfDay();
        $endDay = Carbon::parse($end)->startOfDay();
        $today = Carbon::today();

        while ($cursor->lte($endDay)) {
            $date = $cursor->toDateString();
            $expected += 1.0;

            $isScheduled = $this->dayPolicy->isScheduledWorkday($employee, $date);
            $dayExpectedHours = $isScheduled
                ? $this->attendanceReconciler->expectedPaidHours($employee, $date)
                : 0.0;
            $expectedHours += $dayExpectedHours;

            // Not yet reached — stay in the period denominator only.
            if ($cursor->gt($today)) {
                $remaining += 1.0;
                $cursor->addDay();

                continue;
            }

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

            if (! $isScheduled) {
                // Sunday / off / holiday: monthly pay covers the day “in place”.
                $restPaid += 1.0;
                $cursor->addDay();

                continue;
            }

            $att = $attendanceByDate->get($date);
            if ($att && $this->attendanceCountsAsPaid($att->status)) {
                $dayPaid = (float) ($att->hours_worked ?? 0);
                if ($att->expected_hours !== null && (float) $att->expected_hours > 0) {
                    $dayPaid = min($dayPaid, (float) $att->expected_hours);
                } else {
                    $dayPaid = min($dayPaid, $dayExpectedHours);
                }
                $paidHours += $dayPaid;
                if ($att->status === 'half_day') {
                    $dayFraction = 0.5;
                } elseif ($dayExpectedHours > 0) {
                    $dayFraction = min(1.0, $dayPaid / $dayExpectedHours);
                } else {
                    $dayFraction = 1.0;
                }
                $attended += $dayFraction;
                if (! (bool) ($att->lateness_waived ?? false)) {
                    $lateMinutesTotal += (int) ($att->late_minutes ?? 0);
                }
            } else {
                $absent += 1.0;
            }

            $cursor->addDay();
        }

        $paidDays = $attended + $paidLeave + $restPaid;

        return [
            'expected_days' => round($expected, 2),
            // Keep full precision for pay ratio (display layers round to 2).
            'paid_days' => $paidDays,
            'attended_days' => $attended,
            'rest_days_paid' => round($restPaid, 2),
            'remaining_days' => round($remaining, 2),
            'paid_leave_days' => round($paidLeave, 2),
            'unpaid_leave_days' => round($unpaidLeave, 2),
            'deductible_off_days' => round($deductibleOffDays, 2),
            'non_deductible_off_days' => round($nonDeductibleOffDays, 2),
            'absent_days' => round($absent, 2),
            'expected_hours' => round($expectedHours, 2),
            'paid_hours' => round($paidHours, 2),
            'paid_leave_hours' => round($paidLeaveHours, 2),
            'late_minutes_total' => $lateMinutesTotal,
        ];
    }

    public function expectedWorkDays(Employee $employee, string $start, string $end): float
    {
        return $this->leaveCalculator->countWorkingDays(
            $employee,
            Carbon::parse($start)->startOfDay(),
            Carbon::parse($end)->startOfDay(),
        );
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
