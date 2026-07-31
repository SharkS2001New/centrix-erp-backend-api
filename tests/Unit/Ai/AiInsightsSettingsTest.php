<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiSettingsResolver;
use Tests\TestCase;

class AiInsightsSettingsTest extends TestCase
{
    public function test_normalize_insights_merges_defaults(): void
    {
        $normalized = AiSettingsResolver::normalizeInsights([
            'channels' => ['whatsapp' => true],
            'recipients' => ['emails' => 'ops@example.com, mgr@example.com'],
            'stock_pulse' => ['enabled' => true, 'schedule_time' => '06:45'],
        ]);

        $this->assertTrue($normalized['enabled']);
        $this->assertTrue($normalized['channels']['email']);
        $this->assertTrue($normalized['channels']['whatsapp']);
        $this->assertFalse($normalized['channels']['sms']);
        $this->assertSame(['ops@example.com', 'mgr@example.com'], $normalized['recipients']['emails']);
        $this->assertTrue($normalized['stock_pulse']['enabled']);
        $this->assertSame('06:45', $normalized['stock_pulse']['schedule_time']);
        $this->assertSame(14, $normalized['stock_pulse']['lookback_days']);
        $this->assertFalse($normalized['sales_brief']['enabled']);
    }

    public function test_normalize_insights_rejects_invalid_schedule_time(): void
    {
        $normalized = AiSettingsResolver::normalizeInsights([
            'sales_brief' => ['schedule_time' => 'not-a-time'],
        ]);

        $this->assertSame('07:00', $normalized['sales_brief']['schedule_time']);
    }
}
