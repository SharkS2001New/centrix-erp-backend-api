<?php

namespace Tests\Unit;

use App\Models\WorkShift;
use PHPUnit\Framework\TestCase;

class WorkShiftPresentForAiTest extends TestCase
{
    public function test_present_for_ai_explains_saturday_alternate_hours(): void
    {
        $shift = new WorkShift([
            'shift_name' => 'Morning shift (8–5)',
            'shift_code' => 'AM',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'lunch_minutes' => 60,
            'lunch_required' => true,
            'work_weekdays' => [1, 2, 3, 4, 5, 6],
            'works_saturday' => true,
            'works_sunday' => false,
            'use_alternate_hours' => true,
            'alternate_start_time' => '08:00:00',
            'alternate_end_time' => '13:00:00',
            'alternate_lunch_minutes' => 0,
            'alternate_lunch_required' => false,
        ]);

        $presented = $shift->presentForAi();

        $this->assertSame('Morning shift (8–5)', $presented['name']);
        $this->assertTrue($presented['use_alternate_hours']);
        $this->assertSame('08:00', $presented['weekday_hours']['start_time']);
        $this->assertSame('17:00', $presented['weekday_hours']['end_time']);
        $this->assertSame('08:00', $presented['saturday_sunday_holiday_hours']['start_time']);
        $this->assertSame('13:00', $presented['saturday_sunday_holiday_hours']['end_time']);

        $saturday = collect($presented['schedule_by_day'])->firstWhere('day', 'Saturday');
        $this->assertNotNull($saturday);
        $this->assertTrue($saturday['is_alternate_hours']);
        $this->assertStringContainsString('not a half-day', (string) $saturday['note']);
        $this->assertStringContainsString('half-day', (string) $presented['answer_tip']);
    }
}
