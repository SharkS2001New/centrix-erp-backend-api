<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Services\Attendance\LeaveRequestCalculator;
use App\Services\Hr\HrPayrollSettingsResolver;
use Carbon\Carbon;

class OvertimeRateCalculator
{
    public function __construct(
        protected LeaveRequestCalculator $leaveCalculator,
    ) {}

    /**
     * Hourly rate from monthly salary ÷ scheduled work days in month ÷ shift hours per day.
     */
    public function hourlyFromSalary(Employee $employee, ?string $referenceDate = null): float
    {
        $base = (float) ($employee->base_salary ?? 0);
        if ($base <= 0) {
            return 0.0;
        }

        $date = $referenceDate ?? now()->toDateString();
        $day = Carbon::parse($date);
        $start = $day->copy()->startOfMonth();
        $end = $day->copy()->endOfMonth();

        $workDays = $this->leaveCalculator->countWorkingDays($employee, $start, $end);
        if ($workDays <= 0) {
            $workDays = 22;
        }

        $shiftHours = $this->leaveCalculator->shiftHoursForEmployee($employee);
        if ($shiftHours <= 0) {
            $orgId = (int) ($employee->organization_id ?? 0);
            $shiftHours = (float) (HrPayrollSettingsResolver::forOrganizationId($orgId)['standard_work_hours_per_day'] ?? LeaveRequestCalculator::DEFAULT_SHIFT_HOURS);
        }

        $daily = $base / $workDays;

        return round($daily / $shiftHours, 2);
    }

    public function dailyFromSalary(Employee $employee, ?string $referenceDate = null): float
    {
        $hourly = $this->hourlyFromSalary($employee, $referenceDate);
        $shiftHours = $this->leaveCalculator->shiftHoursForEmployee($employee);
        if ($shiftHours <= 0) {
            $orgId = (int) ($employee->organization_id ?? 0);
            $shiftHours = (float) (HrPayrollSettingsResolver::forOrganizationId($orgId)['standard_work_hours_per_day'] ?? LeaveRequestCalculator::DEFAULT_SHIFT_HOURS);
        }

        return round($hourly * $shiftHours, 2);
    }
}
