<?php

namespace Tests\Unit;

use App\Services\Driving\DrivingScheduleRules;
use PHPUnit\Framework\TestCase;

class DrivingScheduleRulesTest extends TestCase
{
    public function test_lessons_overlap_only_when_their_times_cross(): void
    {
        $this->assertTrue(DrivingScheduleRules::overlaps(
            '2026-10-10 09:00:00',
            '2026-10-10 10:00:00',
            '2026-10-10 09:30:00',
            '2026-10-10 10:30:00',
        ));
        $this->assertFalse(DrivingScheduleRules::overlaps(
            '2026-10-10 09:00:00',
            '2026-10-10 10:00:00',
            '2026-10-10 10:00:00',
            '2026-10-10 11:00:00',
        ));
    }

    public function test_outstanding_fees_return_refunds_and_ignore_collected_cash_as_profit(): void
    {
        $this->assertSame(8500.0, DrivingScheduleRules::invoiceNet(10000, 1500));
        $this->assertSame(3500.0, DrivingScheduleRules::outstanding(8500, 6000, 1000));
    }
}
