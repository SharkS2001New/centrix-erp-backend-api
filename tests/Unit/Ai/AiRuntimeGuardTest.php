<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiRuntimeGuard;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AiRuntimeGuardTest extends TestCase
{
    public function test_health_reports_offline_when_ai_disabled(): void
    {
        config([
            'ai.enabled' => false,
            'ai.provider' => 'gemini',
            'ai.gemini.model' => 'gemini-3.6-flash',
        ]);

        $health = app(AiRuntimeGuard::class)->health();

        $this->assertFalse($health['enabled']);
        $this->assertFalse($health['available']);
        $this->assertSame('OFFLINE', $health['status']);
        $this->assertSame('gemini-3.6-flash', $health['model']);
    }

    public function test_gemini_health_degraded_without_credentials(): void
    {
        config([
            'ai.enabled' => true,
            'ai.provider' => 'gemini',
            'ai.gemini.api_key' => '',
            'ai.gemini.model' => 'gemini-3.6-flash',
            'ai.platform_training.api_key' => '',
        ]);

        $health = app(AiRuntimeGuard::class)->health();

        if ($health['available']) {
            $this->assertContains($health['provider'], ['gemini', 'openai']);
            $this->assertSame('ONLINE', $health['status']);

            return;
        }

        $this->assertFalse($health['available']);
        $this->assertSame('DEGRADED', $health['status']);
    }

    public function test_concurrency_rejects_when_at_capacity(): void
    {
        config(['ai.max_concurrent_requests' => 1]);
        Cache::forget(AiRuntimeGuard::CONCURRENCY_KEY);

        $guard = app(AiRuntimeGuard::class);

        $this->assertTrue($guard->acquire());
        $this->assertFalse($guard->acquire());
        $guard->release();
        $this->assertTrue($guard->acquire());
        $guard->release();
    }

    public function test_concurrency_unlimited_when_zero(): void
    {
        config(['ai.max_concurrent_requests' => 0]);
        Cache::forget(AiRuntimeGuard::CONCURRENCY_KEY);

        $guard = app(AiRuntimeGuard::class);
        $this->assertTrue($guard->acquire());
        $this->assertTrue($guard->acquire());
    }
}
