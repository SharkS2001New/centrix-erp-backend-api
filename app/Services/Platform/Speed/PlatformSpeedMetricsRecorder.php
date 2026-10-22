<?php

namespace App\Services\Platform\Speed;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Hot-path request / query aggregates in Redis (or file cache fallback).
 */
class PlatformSpeedMetricsRecorder
{
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

        try {
            if ($this->useRedis()) {
                $redis = Redis::connection();
                $redis->hincrby($key, 'count', 1);
                $redis->hincrby($key, 'total_ms', max(0, $durationMs));
                $redis->hincrby($key, 'total_bytes', max(0, $bytes));
                if ($status >= 500) {
                    $redis->hincrby($key, 'error_count', 1);
                }
                if ($status === 408 || $status === 504) {
                    $redis->hincrby($key, 'timeout_count', 1);
                }
                $max = (int) $redis->hget($key, 'max_ms');
                if ($durationMs > $max) {
                    $redis->hset($key, 'max_ms', $durationMs);
                }
                $bucket = $this->bucketField($durationMs);
                $redis->hincrby($key, 'h:'.$bucket, 1);
                $redis->hset($key, 'method', strtoupper($method));
                $redis->hset($key, 'route_key', $routeKey);
                $redis->hset($key, 'minute_at', $minute);
                $redis->expire($key, 7200);
                $redis->sadd($this->apiIndexKey(), $key);
                $redis->expire($this->apiIndexKey(), 7200);

                return;
            }

            $this->recordApiViaCache($key, $minute, $method, $routeKey, $durationMs, $status, $bytes);
        } catch (\Throwable $e) {
            Log::debug('platform_speed.record_api_failed', ['error' => $e->getMessage()]);
        }
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

        $minute = now()->format('Y-m-d H:i:00');
        $key = $this->queryKey($minute, $fingerprint);

        try {
            if ($this->useRedis()) {
                $redis = Redis::connection();
                $redis->hincrby($key, 'count', 1);
                $redis->hincrby($key, 'total_ms', max(0, $durationMs));
                if ($slow) {
                    $redis->hincrby($key, 'slow_count', 1);
                }
                if ($nPlusOne) {
                    $redis->hincrby($key, 'n_plus_one_hits', 1);
                }
                $max = (int) $redis->hget($key, 'max_ms');
                if ($durationMs > $max) {
                    $redis->hset($key, 'max_ms', $durationMs);
                }
                $redis->hset($key, 'fingerprint', $fingerprint);
                $redis->hset($key, 'sql_preview', substr($sqlPreview, 0, 500));
                $redis->hset($key, 'minute_at', $minute);
                $redis->expire($key, 7200);
                $redis->sadd($this->queryIndexKey(), $key);
                $redis->expire($this->queryIndexKey(), 7200);

                return;
            }

            $this->recordQueryViaCache($key, $minute, $fingerprint, $sqlPreview, $durationMs, $slow, $nPlusOne);
        } catch (\Throwable $e) {
            Log::debug('platform_speed.record_query_failed', ['error' => $e->getMessage()]);
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

    protected function recordApiViaCache(
        string $key,
        string $minute,
        string $method,
        string $routeKey,
        int $durationMs,
        int $status,
        int $bytes,
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

    protected function recordQueryViaCache(
        string $key,
        string $minute,
        string $fingerprint,
        string $sqlPreview,
        int $durationMs,
        bool $slow,
        bool $nPlusOne,
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
        $row['count'] = (int) $row['count'] + 1;
        $row['total_ms'] = (int) $row['total_ms'] + max(0, $durationMs);
        $row['max_ms'] = max((int) $row['max_ms'], $durationMs);
        if ($slow) {
            $row['slow_count'] = (int) $row['slow_count'] + 1;
        }
        if ($nPlusOne) {
            $row['n_plus_one_hits'] = (int) $row['n_plus_one_hits'] + 1;
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
