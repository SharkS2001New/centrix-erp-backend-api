<?php

namespace App\Services\Background;

use Illuminate\Support\Facades\Cache;

/**
 * Dispatch background-task jobs to the queue, or run them inline when no worker
 * is consuming jobs (typical local `php artisan serve` with QUEUE_CONNECTION=database).
 */
class BackgroundJobDispatcher
{
    public const WORKER_HEARTBEAT_KEY = 'background:queue_worker_seen_at';

    public static function rememberWorkerSeen(): void
    {
        try {
            Cache::put(self::WORKER_HEARTBEAT_KEY, now()->toIso8601String(), now()->addHours(2));
        } catch (\Throwable) {
            // Cache may be unavailable during early boot / tests.
        }
    }

    /**
     * @param  class-string  $jobClass
     */
    public function dispatch(string $jobClass, string $taskId): void
    {
        if ($this->shouldRunInline()) {
            $jobClass::dispatchSync($taskId);

            return;
        }

        $jobClass::dispatch($taskId);
    }

    public function workerRecentlySeen(): bool
    {
        try {
            $raw = Cache::get(self::WORKER_HEARTBEAT_KEY);
        } catch (\Throwable) {
            return false;
        }

        if (! is_string($raw) || $raw === '') {
            return false;
        }

        try {
            $seen = \Illuminate\Support\Carbon::parse($raw);
        } catch (\Throwable) {
            return false;
        }

        $window = max(15, (int) config('background.worker_heartbeat_seconds', 90));

        return $seen->gte(now()->subSeconds($window));
    }

    public function shouldRunInline(): bool
    {
        $driver = (string) config('queue.default', 'sync');
        if ($driver === 'sync') {
            return false;
        }

        if (! $this->allowsInlineFallback()) {
            return false;
        }

        return ! $this->workerRecentlySeen();
    }

    protected function allowsInlineFallback(): bool
    {
        $value = config('background.inline_when_worker_idle');
        if ($value === null || $value === '') {
            return app()->environment('local');
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
