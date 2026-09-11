<?php

namespace Tests\Unit;

use App\Models\EmployeeLeaveDay;
use PHPUnit\Framework\TestCase;

class EmployeeLeaveDayHourlyPayrollTest extends TestCase
{
    public function test_hourly_leave_is_a_shift_fraction_for_payroll(): void
    {
        $leave = new EmployeeLeaveDay([
            'duration_type' => 'hourly',
            'total_hours' => 1,
            'total_days' => 0.13,
        ]);

        $this->assertTrue($leave->isPartialDay());
        $this->assertEqualsWithDelta(0.125, $leave->dayFraction(8), 0.0001);
        $this->assertEqualsWithDelta(1.0, $leave->hoursOnCoveredDay(8), 0.001);
        $this->assertEqualsWithDelta(0.5, $leave->hoursOnCoveredDay(0.5), 0.001);
    }

    public function test_full_shift_of_hourly_leave_is_not_partial(): void
    {
        $leave = new EmployeeLeaveDay([
            'duration_type' => 'hourly',
            'total_hours' => 8,
            'total_days' => 1,
        ]);

        $this->assertFalse($leave->isPartialDay());
        $this->assertEqualsWithDelta(1.0, $leave->dayFraction(8), 0.0001);
    }

    public function test_half_day_leave_stays_half_a_shift(): void
    {
        $leave = new EmployeeLeaveDay([
            'duration_type' => 'half_day',
            'half_day_period' => 'morning',
            'total_days' => 0.5,
            'total_hours' => 4,
        ]);

        $this->assertTrue($leave->isPartialDay());
        $this->assertSame(0.5, $leave->dayFraction(8));
        $this->assertEqualsWithDelta(4.0, $leave->hoursOnCoveredDay(8), 0.001);
    }
}
