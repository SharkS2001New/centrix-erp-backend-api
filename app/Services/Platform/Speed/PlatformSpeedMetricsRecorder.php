<?php

namespace App\Services\Platform\Speed;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Hot-path request / query aggregates in Redis (or file cache fallback).
 * Accrues in memory during the request and flushes once via pipeline.
 */
class PlatformSpeedMetricsRecorder
{
    /** @var array<string, array{minute: string, method: string, route_key: string, count: int, total_ms: int, total_bytes: int, error_count: int, timeout_count: int, max_ms: int, buckets: array<string, int>}> */
    protected array $apiBuffer = [];

    /** @var array<string, array{minute: string, fingerprint: string, sql_preview: string, count: int, total_ms: int, slow_count: int, n_plus_one_hits: int, max_ms: int}> */
    protected array $queryBuffer = [];

    public function __construct(
        protected PlatformSpeedHistogram $histogram,
    ) {}

    public function recordApi(
        string $method,
        string $routeKey,
        int $durationMs,
        int $status,
        int $bytes = 0,
    ): void {
        if (! config('platform_speed.record_requests')) {
            return;
        }

        $minute = now()->format('Y-m-d H:i:00');
        $key = $this->apiKey($minute, strtoupper($method), $routeKey);
        $row = $this->apiBuffer[$key] ?? [
            'minute' => $minute,
            'method' => strtoupper($method),
            'route_key' => $routeKey,
            'count' => 0,
            'total_ms' => 0,
            'total_bytes' => 0,
            'error_count' => 0,
            'timeout_count' => 0,
            'max_ms' => 0,
            'buckets' => [],
        ];
        $row['count']++;
        $row['total_ms'] += max(0, $durationMs);
        $row['total_bytes'] += max(0, $bytes);
        $row['max_ms'] = max($row['max_ms'], $durationMs);
        if ($status >= 500) {
            $row['error_count']++;
        }
        if ($status === 408 || $status === 504) {
            $row['timeout_count']++;
        }
        $bucket = $this->bucketField($durationMs);
        $row['buckets'][$bucket] = (int) ($row['buckets'][$bucket] ?? 0) + 1;
        $this->apiBuffer[$key] = $row;
    }

    public function recordQuery(
        string $fingerprint,
        string $sqlPreview,
        int $durationMs,
        bool $slow,
        bool $nPlusOne = false,
    ): void {
        if (! config('platform_speed.db_listen')) {
            return;
        }

        $sampleRate = (float) config('platform_speed.sample_rate', 1.0);
        if ($sampleRate < 1.0 && (mt_rand() / mt_getrandmax()) > $sampleRate) {
            return;
        }

        $minute = now()->format('Y-m-d H:i:00');
        $key = $this->queryKey($minute, $fingerprint);
        $row = $this->queryBuffer[$key] ?? [
            'minute' => $minute,
            'fingerprint' => $fingerprint,
            'sql_preview' => substr($sqlPreview, 0, 500),
            'count' => 0,
            'total_ms' => 0,
            'slow_count' => 0,
            'n_plus_one_hits' => 0,
            'max_ms' => 0,
        ];
        $row['count']++;
        $row['total_ms'] += max(0, $durationMs);
        $row['max_ms'] = max($row['max_ms'], $durationMs);
        if ($slow) {
            $row['slow_count']++;
        }
        if ($nPlusOne) {
            $row['n_plus_one_hits']++;
        }
        $this->queryBuffer[$key] = $row;
    }

    /** Flush buffered API/query metrics once per request (Redis pipeline when available). */
    public function flush(): void
    {
        if ($this->apiBuffer === [] && $this->queryBuffer === []) {
            return;
        }

        $api = $this->apiBuffer;
        $queries = $this->queryBuffer;
        $this->apiBuffer = [];
        $this->queryBuffer = [];

        try {
            if ($this->useRedis()) {
                $this->flushViaRedisPipeline($api, $queries);

                return;
            }

            foreach ($api as $key => $row) {
                $this->recordApiViaCache(
                    $key,
                    $row['minute'],
                    $row['method'],
                    $row['route_key'],
                    $row['total_ms'],
                    $row['error_count'] > 0 ? 500 : 200,
                    $row['total_bytes'],
                    $row,
                );
            }
            foreach ($queries as $key => $row) {
                $this->recordQueryViaCache(
                    $key,
                    $row['minute'],
                    $row['fingerprint'],
                    $row['sql_preview'],
                    $row['total_ms'],
                    $row['slow_count'] > 0,
                    $row['n_plus_one_hits'] > 0,
                    $row,
                );
            }
        } catch (\Throwable $e) {
            Log::debug('platform_speed.flush_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $api
     * @param  array<string, array<string, mixed>>  $queries
     */
    protected function flushViaRedisPipeline(array $api, array $queries): void
    {
        $redis = Redis::connection();
        $redis->pipeline(function ($pipe) use ($api, $queries) {
            foreach ($api as $key => $row) {
                $pipe->hincrby($key, 'count', (int) $row['count']);
                $pipe->hincrby($key, 'total_ms', (int) $row['total_ms']);
                $pipe->hincrby($key, 'total_bytes', (int) $row['total_bytes']);
                if ((int) $row['error_count'] > 0) {
                    $pipe->hincrby($key, 'error_count', (int) $row['error_count']);
                }
                if ((int) $row['timeout_count'] > 0) {
                    $pipe->hincrby($key, 'timeout_count', (int) $row['timeout_count']);
                }
                $pipe->hset($key, 'max_ms_candidate', (int) $row['max_ms']);
                foreach ($row['buckets'] as $bucket => $count) {
                    $pipe->hincrby($key, 'h:'.$bucket, (int) $count);
                }
                $pipe->hset($key, 'method', (string) $row['method']);
                $pipe->hset($key, 'route_key', (string) $row['route_key']);
                $pipe->hset($key, 'minute_at', (string) $row['minute']);
                $pipe->expire($key, 7200);
                $pipe->sadd($this->apiIndexKey(), $key);
                $pipe->expire($this->apiIndexKey(), 7200);
            }

            foreach ($queries as $key => $row) {
                $pipe->hincrby($key, 'count', (int) $row['count']);
                $pipe->hincrby($key, 'total_ms', (int) $row['total_ms']);
                if ((int) $row['slow_count'] > 0) {
                    $pipe->hincrby($key, 'slow_count', (int) $row['slow_count']);
                }
                if ((int) $row['n_plus_one_hits'] > 0) {
                    $pipe->hincrby($key, 'n_plus_one_hits', (int) $row['n_plus_one_hits']);
                }
                $pipe->hset($key, 'max_ms_candidate', (int) $row['max_ms']);
                $pipe->hset($key, 'fingerprint', (string) $row['fingerprint']);
                $pipe->hset($key, 'sql_preview', (string) $row['sql_preview']);
                $pipe->hset($key, 'minute_at', (string) $row['minute']);
                $pipe->expire($key, 7200);
                $pipe->sadd($this->queryIndexKey(), $key);
                $pipe->expire($this->queryIndexKey(), 7200);
            }
        });

        // Promote max_ms_candidate → max_ms without racing every hget in the hot path.
        foreach (array_merge(array_keys($api), array_keys($queries)) as $key) {
            try {
                $candidate = (int) $redis->hget($key, 'max_ms_candidate');
                $max = (int) $redis->hget($key, 'max_ms');
                if ($candidate > $max) {
                    $redis->hset($key, 'max_ms', $candidate);
                }
                $redis->hdel($key, 'max_ms_candidate');
            } catch (\Throwable) {
                //
            }
        }
    }

    /** @return list<string> */
    public function pendingApiKeys(): array
    {
        try {
            if ($this->useRedis()) {
                return array_values(array_map('strval', Redis::connection()->smembers($this->apiIndexKey()) ?: []));
            }
            $keys = Cache::get($this->apiIndexKey(), []);

            return is_array($keys) ? array_values(array_map('strval', $keys)) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    public function pendingQueryKeys(): array
    {
        try {
            if ($this->useRedis()) {
                return array_values(array_map('strval', Redis::connection()->smembers($this->queryIndexKey()) ?: []));
            }
            $keys = Cache::get($this->queryIndexKey(), []);

            return is_array($keys) ? array_values(array_map('strval', $keys)) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed>|null */
    public function readHash(string $key): ?array
    {
        try {
            if ($this->useRedis()) {
                $data = Redis::connection()->hgetall($key);
                if (! is_array($data) || $data === []) {
                    return null;
                }

                return $data;
            }
            $data = Cache::get($key);
            if (! is_array($data) || $data === []) {
                return null;
            }

            return $data;
        } catch (\Throwable) {
            return null;
        }
    }

    public function forgetKey(string $key, string $indexKey): void
    {
        try {
            if ($this->useRedis()) {
                $redis = Redis::connection();
                $redis->del($key);
                $redis->srem($indexKey, $key);

                return;
            }
            Cache::forget($key);
            $keys = Cache::get($indexKey, []);
            if (is_array($keys)) {
                Cache::forever($indexKey, array_values(array_filter($keys, fn ($k) => $k !== $key)));
            }
        } catch (\Throwable) {
            //
        }
    }

    public function apiIndexKey(): string
    {
        return $this->prefix().'api_index';
    }

    public function queryIndexKey(): string
    {
        return $this->prefix().'query_index';
    }

    protected function apiKey(string $minute, string $method, string $routeKey): string
    {
        return $this->prefix().'api:'.md5($minute.'|'.$method.'|'.$routeKey);
    }

    protected function queryKey(string $minute, string $fingerprint): string
    {
        return $this->prefix().'query:'.md5($minute.'|'.$fingerprint);
    }

    protected function prefix(): string
    {
        return (string) config('platform_speed.redis_prefix', 'platform_speed:');
    }

    protected function useRedis(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            if (config('cache.default') !== 'redis' && ! env('REDIS_HOST')) {
                return $ok = false;
            }
            Redis::connection()->ping();

            return $ok = true;
        } catch (\Throwable) {
            return $ok = false;
        }
    }

    protected function bucketField(int $ms): string
    {
        foreach ($this->histogram->bounds() as $bound) {
            if ($ms <= $bound) {
                return (string) $bound;
            }
        }

        return 'inf';
    }

    /**
     * @param  array<string, mixed>|null  $buffered
     */
    protected function recordApiViaCache(
        string $key,
        string $minute,
        string $method,
        string $routeKey,
        int $durationMs,
        int $status,
        int $bytes,
        ?array $buffered = null,
    ): void {
        $row = Cache::get($key, [
            'count' => 0,
            'error_count' => 0,
            'timeout_count' => 0,
            'total_ms' => 0,
            'max_ms' => 0,
            'total_bytes' => 0,
            'histogram' => $this->histogram->emptyBuckets(),
            'method' => strtoupper($method),
            'route_key' => $routeKey,
            'minute_at' => $minute,
        ]);
        if ($buffered) {
            $row['count'] = (int) $row['count'] + (int) $buffered['count'];
            $row['total_ms'] = (int) $row['total_ms'] + (int) $buffered['total_ms'];
            $row['total_bytes'] = (int) $row['total_bytes'] + (int) $buffered['total_bytes'];
            $row['max_ms'] = max((int) $row['max_ms'], (int) $buffered['max_ms']);
            $row['error_count'] = (int) $row['error_count'] + (int) $buffered['error_count'];
            $row['timeout_count'] = (int) $row['timeout_count'] + (int) $buffered['timeout_count'];
            $hist = is_array($row['histogram'] ?? null) ? $row['histogram'] : $this->histogram->emptyBuckets();
            foreach ($buffered['buckets'] as $bucket => $count) {
                $field = 'h:'.$bucket;
                // histogram helper stores by bound key; keep both shapes compatible
                if (isset($hist[$bucket])) {
                    $hist[$bucket] = (int) $hist[$bucket] + (int) $count;
                } else {
                    $this->histogram->bucketIndex((int) $bucket === 0 ? 1 : (int) $bucket, $hist);
                }
            }
            $row['histogram'] = $hist;
        } else {
            $row['count'] = (int) $row['count'] + 1;
            $row['total_ms'] = (int) $row['total_ms'] + max(0, $durationMs);
            $row['total_bytes'] = (int) $row['total_bytes'] + max(0, $bytes);
            $row['max_ms'] = max((int) $row['max_ms'], $durationMs);
            if ($status >= 500) {
                $row['error_count'] = (int) $row['error_count'] + 1;
            }
            if ($status === 408 || $status === 504) {
                $row['timeout_count'] = (int) $row['timeout_count'] + 1;
            }
            $hist = is_array($row['histogram'] ?? null) ? $row['histogram'] : $this->histogram->emptyBuckets();
            $this->histogram->bucketIndex($durationMs, $hist);
            $row['histogram'] = $hist;
        }
        Cache::put($key, $row, 7200);
        $index = Cache::get($this->apiIndexKey(), []);
        if (! is_array($index)) {
            $index = [];
        }
        if (! in_array($key, $index, true)) {
            $index[] = $key;
            Cache::forever($this->apiIndexKey(), $index);
        }
    }

    /**
     * @param  array<string, mixed>|null  $buffered
     */
    protected function recordQueryViaCache(
        string $key,
        string $minute,
        string $fingerprint,
        string $sqlPreview,
        int $durationMs,
        bool $slow,
        bool $nPlusOne,
        ?array $buffered = null,
    ): void {
        $row = Cache::get($key, [
            'count' => 0,
            'slow_count' => 0,
            'n_plus_one_hits' => 0,
            'total_ms' => 0,
            'max_ms' => 0,
            'fingerprint' => $fingerprint,
            'sql_preview' => substr($sqlPreview, 0, 500),
            'minute_at' => $minute,
        ]);
        if ($buffered) {
            $row['count'] = (int) $row['count'] + (int) $buffered['count'];
            $row['total_ms'] = (int) $row['total_ms'] + (int) $buffered['total_ms'];
            $row['max_ms'] = max((int) $row['max_ms'], (int) $buffered['max_ms']);
            $row['slow_count'] = (int) $row['slow_count'] + (int) $buffered['slow_count'];
            $row['n_plus_one_hits'] = (int) $row['n_plus_one_hits'] + (int) $buffered['n_plus_one_hits'];
        } else {
            $row['count'] = (int) $row['count'] + 1;
            $row['total_ms'] = (int) $row['total_ms'] + max(0, $durationMs);
            $row['max_ms'] = max((int) $row['max_ms'], $durationMs);
            if ($slow) {
                $row['slow_count'] = (int) $row['slow_count'] + 1;
            }
            if ($nPlusOne) {
                $row['n_plus_one_hits'] = (int) $row['n_plus_one_hits'] + 1;
            }
        }
        Cache::put($key, $row, 7200);
        $index = Cache::get($this->queryIndexKey(), []);
        if (! is_array($index)) {
            $index = [];
        }
        if (! in_array($key, $index, true)) {
            $index[] = $key;
            Cache::forever($this->queryIndexKey(), $index);
        }
    }
}
