<?php

namespace Tests\Unit\Background;

use Tests\TestCase;

class BackgroundJobDispatcherTest extends TestCase
{
    public function test_queue_retry_after_exceeds_long_running_job_timeout(): void
    {
        $this->assertGreaterThanOrEqual(7200, (int) config('queue.connections.redis.retry_after'));
        $this->assertGreaterThanOrEqual(7200, (int) config('queue.connections.database.retry_after'));
    }
}
