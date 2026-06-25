<?php

namespace App\Services\Background;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

class BackgroundTaskQueueService
{
    /** @var list<class-string> */
    private const UNIQUE_TASK_JOBS = [
        \App\Jobs\GenerateReportExportJob::class,
        \App\Jobs\PaginatedFetchJob::class,
        \App\Jobs\ReportRunJob::class,
    ];

    /**
     * Best-effort removal of queued (not yet running) Laravel jobs for a background task.
     */
    public function removePendingJobs(string $taskId): int
    {
        $removed = 0;
        $driver = (string) config('queue.default', 'database');

        if ($driver === 'redis') {
            $removed += $this->removePendingRedisJobs($taskId);
        }

        if ($driver === 'database' && Schema::hasTable('jobs')) {
            $removed += $this->removePendingDatabaseJobs($taskId);
        }

        $this->releaseUniqueJobLocks($taskId);

        return $removed;
    }

    protected function removePendingDatabaseJobs(string $taskId): int
    {
        try {
            return DB::table((string) config('queue.connections.database.table', 'jobs'))
                ->where('payload', 'like', '%'.$this->escapeLike($taskId).'%')
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('Could not delete pending database queue jobs for background task', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    protected function removePendingRedisJobs(string $taskId): int
    {
        $removed = 0;

        try {
            $connection = (string) config('queue.connections.redis.connection', 'default');
            $queueName = (string) config('queue.connections.redis.queue', 'default');
            $redis = Redis::connection($connection);
            $queueKey = $this->redisQueueKey($queueName);

            $removed += $this->removeMatchingRedisListJobs($redis, $queueKey, $taskId);
            $removed += $this->removeMatchingRedisDelayedJobs($redis, $queueKey.':delayed', $taskId);
        } catch (\Throwable $e) {
            Log::warning('Could not delete pending redis queue jobs for background task', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ]);
        }

        return $removed;
    }

    /**
     * @param  \Illuminate\Redis\Connections\Connection  $redis
     */
    protected function removeMatchingRedisListJobs($redis, string $key, string $taskId): int
    {
        $removed = 0;
        $jobs = $redis->lrange($key, 0, -1);

        if (! is_array($jobs)) {
            return 0;
        }

        foreach ($jobs as $job) {
            if (! is_string($job) || ! str_contains($job, $taskId)) {
                continue;
            }

            if ((int) $redis->lrem($key, 0, $job) > 0) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @param  \Illuminate\Redis\Connections\Connection  $redis
     */
    protected function removeMatchingRedisDelayedJobs($redis, string $key, string $taskId): int
    {
        $removed = 0;
        $jobs = $redis->zrange($key, 0, -1);

        if (! is_array($jobs)) {
            return 0;
        }

        foreach ($jobs as $job) {
            if (! is_string($job) || ! str_contains($job, $taskId)) {
                continue;
            }

            if ((int) $redis->zrem($key, $job) > 0) {
                $removed++;
            }
        }

        return $removed;
    }

    protected function redisQueueKey(string $queueName): string
    {
        $prefix = (string) config('database.redis.options.prefix', '');

        return $prefix.'queues:'.$queueName;
    }

    protected function releaseUniqueJobLocks(string $taskId): void
    {
        foreach (self::UNIQUE_TASK_JOBS as $class) {
            $key = 'laravel_unique_job:'.$class.':'.$taskId;

            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // Best-effort — cooperative cancellation still applies once the worker runs.
            }
        }
    }

    protected function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
