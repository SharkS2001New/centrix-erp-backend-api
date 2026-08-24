<?php

namespace Tests\Unit\Ai;

use App\Models\Organization;
use App\Services\Ai\AiSalesDateResolver;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Tests\TestCase;

class AiSalesDateResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_yesterday_uses_application_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 01:30:00', AppTimezone::name()));

        [$from, $to] = AiSalesDateResolver::resolve(['relative_date' => 'yesterday']);

        $this->assertSame('2026-08-23', $from);
        $this->assertSame('2026-08-23', $to);
    }

    public function test_calendar_anchor_exposes_today_and_yesterday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 10:00:00', AppTimezone::name()));

        $anchor = AiSalesDateResolver::calendarAnchor(new Organization);

        $this->assertSame('2026-08-24', $anchor['today']);
        $this->assertSame('2026-08-23', $anchor['yesterday']);
        $this->assertSame(AppTimezone::name(), $anchor['timezone']);
    }

    public function test_this_month_and_year_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 10:00:00', AppTimezone::name()));

        [$from, $to] = AiSalesDateResolver::resolve(['relative_date' => 'this_month']);
        $this->assertSame('2026-08-01', $from);
        $this->assertSame('2026-08-31', $to);

        [$from2, $to2] = AiSalesDateResolver::resolve(['relative_date' => 'this_month_to_date']);
        $this->assertSame('2026-08-01', $from2);
        $this->assertSame('2026-08-24', $to2);

        [$from3, $to3] = AiSalesDateResolver::resolve(['year_month' => '2026-07']);
        $this->assertSame('2026-07-01', $from3);
        $this->assertSame('2026-07-31', $to3);

        [$from4, $to4] = AiSalesDateResolver::resolve(['relative_date' => 'last_month']);
        $this->assertSame('2026-07-01', $from4);
        $this->assertSame('2026-07-31', $to4);

        [$from5, $to5] = AiSalesDateResolver::resolve(['month' => 'august', 'year' => 2026]);
        $this->assertSame('2026-08-01', $from5);
        $this->assertSame('2026-08-31', $to5);
    }
}
