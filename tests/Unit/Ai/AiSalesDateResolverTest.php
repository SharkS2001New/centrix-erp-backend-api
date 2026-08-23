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
}
