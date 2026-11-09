<?php

namespace App\Services\Kra;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Caps automatic KRA recovery (queued receipts / product registration) at 3 tries
 * with staggered delays. After that, operators retry manually from the UI.
 */
class KraAutoRetryPolicy
{
    public const MAX_ATTEMPTS = 3;

    /**
     * Delay before the next auto-retry after N completed auto attempts.
     * 0 → 5 minutes, 1 → 20 minutes, 2 → 60 minutes.
     *
     * @var list<int>
     */
    public const DELAY_SECONDS_AFTER_ATTEMPTS = [
        5 * 60,
        20 * 60,
        60 * 60,
    ];

    /**
     * Laravel job backoff between attempts for RegisterKraProductsJob (tries=3).
     *
     * @var list<int>
     */
    public const JOB_BACKOFF_SECONDS = [
        5 * 60,
        20 * 60,
        60 * 60,
    ];

    public static function maxAttempts(): int
    {
        return max(1, (int) config('kra.auto_retry_max_attempts', self::MAX_ATTEMPTS));
    }

    /** @param  array<string, mixed>  $payload */
    public static function attemptCount(array $payload): int
    {
        return max(0, (int) ($payload['auto_retry_count'] ?? 0));
    }

    /** @param  array<string, mixed>  $payload */
    public static function isExhausted(array $payload): bool
    {
        if (! empty($payload['auto_retry_exhausted'])) {
            return true;
        }

        return self::attemptCount($payload) >= self::maxAttempts();
    }

    /**
     * Whether a scheduled/worker auto-retry may run now.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function shouldAutoRetry(array $payload, ?CarbonInterface $now = null): bool
    {
        if (self::isExhausted($payload)) {
            return false;
        }

        // Never auto-retried yet — run immediately (checkout defers eTIMS upload here).
        $completed = self::attemptCount($payload);
        if ($completed === 0) {
            return true;
        }

        $now = $now ? Carbon::instance($now) : now();
        $lastAt = self::lastAttemptAt($payload);
        if ($lastAt === null) {
            return true;
        }

        $delay = self::delaySecondsAfterAttempts($completed);

        return $lastAt->copy()->addSeconds($delay)->lte($now);
    }

    public static function delaySecondsAfterAttempts(int $completedAttempts): int
    {
        $delays = config('kra.auto_retry_delay_seconds', self::DELAY_SECONDS_AFTER_ATTEMPTS);
        if (! is_array($delays) || $delays === []) {
            $delays = self::DELAY_SECONDS_AFTER_ATTEMPTS;
        }

        $index = max(0, min(count($delays) - 1, $completedAttempts));

        return max(60, (int) $delays[$index]);
    }

    /**
     * Seed tracking on the first soft-fail / pending state.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function initializePayload(array $payload, ?CarbonInterface $now = null): array
    {
        if (! array_key_exists('auto_retry_count', $payload)) {
            $payload['auto_retry_count'] = 0;
        }
        // Do not stamp auto_retry_last_at until markAttempt — otherwise the first
        // scheduled upload waits DELAY_SECONDS_AFTER_ATTEMPTS[0] (5+ minutes).
        $payload['auto_retry_exhausted'] = self::isExhausted($payload);

        return $payload;
    }

    /**
     * Record that an automatic retry is being consumed.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function markAttempt(array $payload, ?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::instance($now) : now();
        $count = self::attemptCount($payload) + 1;
        $payload['auto_retry_count'] = $count;
        $payload['auto_retry_last_at'] = $now->toIso8601String();
        $payload['auto_retry_exhausted'] = $count >= self::maxAttempts();
        if ($payload['auto_retry_exhausted']) {
            $payload['auto_retry_exhausted_at'] = $now->toIso8601String();
        }

        return $payload;
    }

    /** @param  array<string, mixed>  $payload */
    public static function lastAttemptAt(array $payload): ?Carbon
    {
        $raw = $payload['auto_retry_last_at'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
