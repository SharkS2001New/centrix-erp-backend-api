<?php

namespace Tests\Unit;

use App\Services\Payroll\PayrollRunScheduleService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class PayrollRunScheduleServiceTest extends TestCase
{
    private PayrollRunScheduleService $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schedule = new PayrollRunScheduleService;
    }

    public function test_june_payroll_on_last_day_of_june(): void
    {
        $this->assertTrue(
            $this->schedule->canRunPayrollForRange(
                Carbon::parse('2026-06-01'),
                Carbon::parse('2026-06-30'),
                Carbon::parse('2026-06-30'),
            ),
        );
    }

    public function test_june_payroll_not_allowed_mid_june(): void
    {
        $this->assertFalse(
            $this->schedule->canRunPayrollForRange(
                Carbon::parse('2026-06-01'),
                Carbon::parse('2026-06-30'),
                Carbon::parse('2026-06-15'),
            ),
        );
    }

    public function test_june_payroll_in_first_week_of_july(): void
    {
        $this->assertTrue(
            $this->schedule->canRunPayrollForRange(
                Carbon::parse('2026-06-01'),
                Carbon::parse('2026-06-30'),
                Carbon::parse('2026-07-03'),
            ),
        );
    }

    public function test_june_payroll_blocked_after_first_week_of_july(): void
    {
        $this->assertFalse(
            $this->schedule->canRunPayrollForRange(
                Carbon::parse('2026-06-01'),
                Carbon::parse('2026-06-30'),
                Carbon::parse('2026-07-15'),
            ),
        );
    }

    public function test_upcoming_month_blocked(): void
    {
        $this->assertFalse(
            $this->schedule->canRunPayrollForRange(
                Carbon::parse('2026-08-01'),
                Carbon::parse('2026-08-31'),
                Carbon::parse('2026-06-30'),
            ),
        );
    }
}
