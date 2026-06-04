<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeaveDay;
use App\Models\EmployeeOvertime;
use App\Models\PayPeriod;
use App\Services\Attendance\AttendanceDayPolicy;
use App\Services\Attendance\LeaveRequestCalculator;
use Carbon\Carbon;

class PayrollEarningsService
{
    public function __construct(
        protected AttendanceDayPolicy $dayPolicy,
        protected LeaveRequestCalculator $leaveCalculator,
        protected OvertimeRateCalculator $overtimeRates,
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

        $start = $period->period_start->format('Y-m-d');
        $end = $period->period_end->format('Y-m-d');

        $attendanceSummary = $useProration
            ? $this->summarizeAttendance($employee, $start, $end)
            : null;

        $expectedDays = $attendanceSummary['expected_days'] ?? $this->expectedWorkDays($employee, $start, $end);
        if ($expectedDays <= 0) {
            return null;
        }

        $dailyRate = round($contractBasic / $expectedDays, 2);
        $paidDays = $useProration
            ? ($attendanceSummary['paid_days'] ?? $expectedDays)
            : $expectedDays;

        $payrollBasic = $useProration
            ? round($dailyRate * $paidDays, 2)
            : $contractBasic;

        $allowanceBreakdown = $this->resolveAllowances(
            $employee,
            $contractBasic,
            $paidDays,
            $expectedDays,
            $includeAllowances,
            $useProration,
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
            $built = app(PayrollOtherDeductionsBuilder::class)->build($employee, $contractGrossForOther);
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
                'allowance_source' => $allowanceBreakdown['source'],
                'allowance_lines' => $allowanceBreakdown['lines'],
                'allowances_period' => $allowances,
                'expected_work_days' => $expectedDays,
                'paid_work_days' => $paidDays,
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
     *   attended_days: float,
     *   paid_leave_days: float,
     *   unpaid_leave_days: float,
     *   absent_days: float
     * }
     */
    public function summarizeAttendance(Employee $employee, string $start, string $end): array
    {
        $leaves = EmployeeLeaveDay::query()
            ->where('employee_id', $employee->id)
            ->whereDate('end_date', '>=', $start)
            ->whereDate('start_date', '<=', $end)
            ->whereNull('payroll_run_id')
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
        $paidLeave = 0.0;
        $unpaidLeave = 0.0;

        $cursor = Carbon::parse($start)->startOfDay();
        $endDay = Carbon::parse($end)->startOfDay();

        while ($cursor->lte($endDay)) {
            $date = $cursor->toDateString();
            if (! $this->dayPolicy->isScheduledWorkday($employee, $date)) {
                $cursor->addDay();

                continue;
            }

            $expected += 1.0;
            $dayFraction = 1.0;

            $leave = $leaves->first(fn (EmployeeLeaveDay $l) => $l->coversDate($date));
            if ($leave) {
                $dayFraction = $leave->duration_type === 'half_day' ? 0.5 : 1.0;
                if ($this->leaveIsUnpaid($leave)) {
                    $unpaidLeave += $dayFraction;
                } else {
                    $paidLeave += $dayFraction;
                }
                $cursor->addDay();

                continue;
            }

            $att = $attendanceByDate->get($date);
            if ($att && $this->attendanceCountsAsPaid($att->status)) {
                $attended += $att->status === 'half_day' ? 0.5 : 1.0;
            }

            $cursor->addDay();
        }

        $paidDays = round($attended + $paidLeave, 2);
        $absent = round(max(0, $expected - $paidDays - $unpaidLeave), 2);

        return [
            'expected_days' => round($expected, 2),
            'paid_days' => $paidDays,
            'attended_days' => round($attended, 2),
            'paid_leave_days' => round($paidLeave, 2),
            'unpaid_leave_days' => round($unpaidLeave, 2),
            'absent_days' => $absent,
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
            }
        }

        if ($monthly <= 0) {
            $monthly = round($contractBasic * 0.1, 2);
            $source = 'default_percent';
            $lines = [['id' => null, 'name' => 'Default (10% of basic)', 'amount' => $monthly]];
        }

        if ($useProration && $expectedDays > 0) {
            $ratio = $paidDays / $expectedDays;
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

    protected function leaveIsUnpaid(EmployeeLeaveDay $leave): bool
    {
        if ($leave->deduct_from === 'unpaid' || $leave->leave_type === 'unpaid') {
            return true;
        }

        return false;
    }

    protected function attendanceCountsAsPaid(?string $status): bool
    {
        return in_array($status, ['present', 'late', 'half_day'], true);
    }
}
