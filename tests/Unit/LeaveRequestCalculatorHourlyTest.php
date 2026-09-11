<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Services\Attendance\AttendanceDayPolicy;
use App\Services\Attendance\LeaveRequestCalculator;
use Mockery;
use PHPUnit\Framework\TestCase;

class LeaveRequestCalculatorHourlyTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_hourly_leave_converts_hours_to_a_day_fraction(): void
    {
        $policy = Mockery::mock(AttendanceDayPolicy::class);
        $policy->shouldReceive('isScheduledWorkday')->once()->andReturn(true);
        $calc = new LeaveRequestCalculator($policy);
        $employee = new Employee(['shift_id' => null]);

        $result = $calc->calculate($employee, '2026-09-11', '2026-09-11', 'hourly', null, 1);

        $this->assertSame(1.0, $result['total_hours']);
        $this->assertEqualsWithDelta(0.125, $result['total_days'], 0.0001);
        $this->assertTrue($result['same_day']);
        $this->assertSame(8.0, $result['shift_hours_per_day']);
    }

    public function test_hourly_leave_rejects_more_hours_than_the_shift(): void
    {
        $policy = Mockery::mock(AttendanceDayPolicy::class);
        $calc = new LeaveRequestCalculator($policy);
        $employee = new Employee(['shift_id' => null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Hours cannot exceed the shift length');
        $calc->calculate($employee, '2026-09-11', '2026-09-11', 'hourly', null, 9);
    }

    public function test_hourly_leave_requires_a_single_date(): void
    {
        $policy = Mockery::mock(AttendanceDayPolicy::class);
        $calc = new LeaveRequestCalculator($policy);
        $employee = new Employee(['shift_id' => null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('single date');
        $calc->calculate($employee, '2026-09-11', '2026-09-12', 'hourly', null, 1);
    }
}
