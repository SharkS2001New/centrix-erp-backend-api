<?php

namespace Tests\Unit;

use App\Services\Kra\KraAutoRetryPolicy;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class KraAutoRetryPolicyTest extends TestCase
{
    public function test_allows_first_retry_immediately_when_untracked(): void
    {
        $this->assertTrue(KraAutoRetryPolicy::shouldAutoRetry([]));
    }

    public function test_initialize_payload_still_allows_immediate_first_attempt(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');

        $payload = KraAutoRetryPolicy::initializePayload([]);
        $this->assertSame(0, KraAutoRetryPolicy::attemptCount($payload));
        $this->assertTrue(KraAutoRetryPolicy::shouldAutoRetry($payload));

        Carbon::setTestNow();
    }

    public function test_respects_staggered_delays_and_max_attempts(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');

        $payload = KraAutoRetryPolicy::initializePayload([]);
        $payload = KraAutoRetryPolicy::markAttempt($payload);
        $this->assertSame(1, KraAutoRetryPolicy::attemptCount($payload));
        // After 1 attempt: wait DELAY[1] = 20 minutes before the next.
        $this->assertFalse(KraAutoRetryPolicy::shouldAutoRetry($payload));

        Carbon::setTestNow('2026-11-08 10:20:00');
        $this->assertTrue(KraAutoRetryPolicy::shouldAutoRetry($payload));

        $payload = KraAutoRetryPolicy::markAttempt($payload);
        $this->assertSame(2, KraAutoRetryPolicy::attemptCount($payload));
        $this->assertFalse(KraAutoRetryPolicy::shouldAutoRetry($payload));

        Carbon::setTestNow('2026-11-08 11:20:00');
        $this->assertTrue(KraAutoRetryPolicy::shouldAutoRetry($payload));

        $payload = KraAutoRetryPolicy::markAttempt($payload);
        $this->assertSame(3, KraAutoRetryPolicy::attemptCount($payload));
        $this->assertTrue(KraAutoRetryPolicy::isExhausted($payload));
        $this->assertFalse(KraAutoRetryPolicy::shouldAutoRetry($payload));

        Carbon::setTestNow();
    }
}
