<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeCashAdvance;
use App\Models\EmployeeKpi;
use App\Models\EmployeeLeaveDay;
use App\Models\EmployeeOvertime;
use App\Models\PayrollLine;
use Carbon\Carbon;

class EmployeeKpiService
{
    /** @return array{computed: list<array<string, mixed>>, tracked: list<array<string, mixed>>} */
    public function summary(Employee $employee): array
    {
        return [
            'computed' => $this->computedMetrics($employee),
            'tracked' => $this->trackedKpis($employee),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function computedMetrics(Employee $employee): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();
        $yearStart = $now->copy()->startOfYear()->toDateString();

        $attendanceDays = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$monthStart, $monthEnd])
            ->whereIn('status', ['present', 'late', 'half_day'])
            ->count();

        $workingDays = max(1, $this->workingDaysInRange($monthStart, $monthEnd));
        $attendanceRate = round(($attendanceDays / $workingDays) * 100, 1);

        $overtimeHours = (float) EmployeeOvertime::query()
            ->where('employee_id', $employee->id)
            ->where('work_date', '>=', $yearStart)
            ->where('status', '!=', 'rejected')
            ->sum('hours');

        $leaveDays = (float) EmployeeLeaveDay::query()
            ->where('employee_id', $employee->id)
            ->where('start_date', '>=', $yearStart)
            ->sum('total_days');

        $ytdNetPay = (float) PayrollLine::query()
            ->where('employee_id', $employee->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('run_date', '>=', $yearStart))
            ->sum('net_pay');

        $advanceBalance = (float) EmployeeCashAdvance::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'open')
            ->sum('balance');

        $directReports = Employee::query()
            ->where('reports_to_employee_id', $employee->id)
            ->where('is_active', true)
            ->count();

        $tenureMonths = $employee->hire_date
            ? Carbon::parse($employee->hire_date)->diffInMonths($now)
            : null;

        return [
            $this->metric('tenure_months', 'Tenure', $tenureMonths, 'months', 'Months since hire date'),
            $this->metric('attendance_days_mtd', 'Attendance days (MTD)', $attendanceDays, 'days', 'Present days this month'),
            $this->metric('attendance_rate_mtd', 'Attendance rate (MTD)', $attendanceRate, '%', 'Present days vs working days this month'),
            $this->metric('overtime_hours_ytd', 'Overtime hours (YTD)', round($overtimeHours, 1), 'hours', 'Approved overtime this year'),
            $this->metric('leave_days_ytd', 'Leave days (YTD)', round($leaveDays, 1), 'days', 'Leave taken this year'),
            $this->metric('ytd_net_pay', 'Net pay (YTD)', round($ytdNetPay, 2), 'KES', 'Processed payroll net pay this year'),
            $this->metric('cash_advance_balance', 'Cash advance balance', round($advanceBalance, 2), 'KES', 'Outstanding open cash advances'),
            $this->metric('direct_reports', 'Direct reports', $directReports, 'people', 'Active employees reporting to this person'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function trackedKpis(Employee $employee): array
    {
        return EmployeeKpi::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeKpi $kpi) => [
                'id' => $kpi->id,
                'kpi_code' => $kpi->kpi_code,
                'label' => $kpi->label,
                'period_start' => $kpi->period_start?->format('Y-m-d'),
                'period_end' => $kpi->period_end?->format('Y-m-d'),
                'target_value' => $kpi->target_value !== null ? (float) $kpi->target_value : null,
                'actual_value' => $kpi->actual_value !== null ? (float) $kpi->actual_value : null,
                'unit' => $kpi->unit,
                'notes' => $kpi->notes,
                'progress_pct' => $kpi->progressPercent(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function metric(string $key, string $label, mixed $value, string $unit, string $hint): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'unit' => $unit,
            'hint' => $hint,
        ];
    }

    private function workingDaysInRange(string $start, string $end): int
    {
        $days = 0;
        $cursor = Carbon::parse($start);
        $endDate = Carbon::parse($end);

        while ($cursor->lte($endDate)) {
            if (! $cursor->isWeekend()) {
                $days++;
            }
            $cursor->addDay();
        }

        return $days;
    }
}
