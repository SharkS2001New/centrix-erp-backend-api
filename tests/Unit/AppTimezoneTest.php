<?php

namespace Tests\Unit;

use App\Support\AppTimezone;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AppTimezoneTest extends TestCase
{
    #[Test]
    public function default_timezone_is_nairobi(): void
    {
        $this->assertSame('Africa/Nairobi', AppTimezone::name());
        $this->assertSame('Africa/Nairobi', config('app.timezone'));
    }

    #[Test]
    public function calendar_day_bounds_use_nairobi_not_utc(): void
    {
        $start = AppTimezone::parseDateStart('2026-06-20');
        $end = AppTimezone::parseDateEnd('2026-06-20');

        $this->assertSame('2026-06-20 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-20 23:59:59', $end->format('Y-m-d H:i:s'));
        $this->assertSame('Africa/Nairobi', $start->timezone->getName());
    }

    #[Test]
    public function normalize_converts_utc_instant_to_nairobi(): void
    {
        $local = AppTimezone::normalize('2026-06-20T09:30:00Z');

        $this->assertInstanceOf(Carbon::class, $local);
        $this->assertSame('2026-06-20 12:30:00', $local?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function report_period_defaults_to_nairobi_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 15:45:00', 'Africa/Nairobi'));

        $period = AppTimezone::reportPeriod(null, null, 30);

        $this->assertSame('2026-06-20', $period['to']->toDateString());
        $this->assertSame('2026-05-22', $period['from']->toDateString());

        Carbon::setTestNow();
    }
}
