<?php

namespace Tests\Unit\Background;

use App\Services\Background\BackgroundJobDispatcher;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BackgroundJobDispatcherTest extends TestCase
{
    public function test_database_queue_without_worker_runs_inline_when_enabled(): void
    {
        config([
            'queue.default' => 'database',
            'background.inline_when_worker_idle' => true,
        ]);
        Cache::forget(BackgroundJobDispatcher::WORKER_HEARTBEAT_KEY);

        $this->assertTrue(app(BackgroundJobDispatcher::class)->shouldRunInline());
    }

    public function test_fresh_worker_heartbeat_keeps_jobs_on_the_queue(): void
    {
        config([
            'queue.default' => 'database',
            'background.inline_when_worker_idle' => true,
        ]);
        BackgroundJobDispatcher::rememberWorkerSeen();

        $this->assertFalse(app(BackgroundJobDispatcher::class)->shouldRunInline());
    }

    public function test_production_override_does_not_inline(): void
    {
        config([
            'queue.default' => 'database',
            'background.inline_when_worker_idle' => false,
        ]);
        Cache::forget(BackgroundJobDispatcher::WORKER_HEARTBEAT_KEY);

        $this->assertFalse(app(BackgroundJobDispatcher::class)->shouldRunInline());
    }

    public function test_sync_driver_does_not_need_inline_fallback(): void
    {
        config([
            'queue.default' => 'sync',
            'background.inline_when_worker_idle' => true,
        ]);
        Cache::forget(BackgroundJobDispatcher::WORKER_HEARTBEAT_KEY);

        $this->assertFalse(app(BackgroundJobDispatcher::class)->shouldRunInline());
    }
}
