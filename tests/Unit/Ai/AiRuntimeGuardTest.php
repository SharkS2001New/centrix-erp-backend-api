<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiRuntimeGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiRuntimeGuardTest extends TestCase
{
    public function test_health_reports_offline_when_ai_disabled(): void
    {
        config([
            'ai.enabled' => false,
            'ai.provider' => 'ollama',
            'ai.ollama.model' => 'llama3.2',
        ]);

        $health = app(AiRuntimeGuard::class)->health();

        $this->assertFalse($health['enabled']);
        $this->assertFalse($health['available']);
        $this->assertSame('OFFLINE', $health['status']);
        $this->assertSame('llama3.2', $health['model']);
    }

    public function test_ollama_health_online_when_model_present(): void
    {
        config([
            'ai.enabled' => true,
            'ai.provider' => 'ollama',
            'ai.ollama.base_url' => 'http://ollama.test:11434',
            'ai.ollama.model' => 'llama3.2',
            'ai.ollama.request_timeout' => 5,
        ]);

        Http::fake([
            'http://ollama.test:11434/api/tags' => Http::response([
                'models' => [
                    ['name' => 'llama3.2:latest'],
                ],
            ]),
        ]);

        $health = app(AiRuntimeGuard::class)->health();

        $this->assertTrue($health['available']);
        $this->assertSame('ONLINE', $health['status']);
        $this->assertSame('ollama', $health['provider']);
    }

    public function test_ollama_health_degraded_when_model_missing(): void
    {
        config([
            'ai.enabled' => true,
            'ai.provider' => 'ollama',
            'ai.ollama.base_url' => 'http://ollama.test:11434',
            'ai.ollama.model' => 'llama3.2',
        ]);

        Http::fake([
            'http://ollama.test:11434/api/tags' => Http::response([
                'models' => [
                    ['name' => 'mistral:latest'],
                ],
            ]),
        ]);

        $health = app(AiRuntimeGuard::class)->health();

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
