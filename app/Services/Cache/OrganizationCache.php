<?php

namespace App\Services\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;

class OrganizationCache
{
    public static function tag(int $organizationId): string
    {
        return 'org:'.$organizationId;
    }

    public static function supportsTags(): bool
    {
        return in_array(config('cache.default'), ['redis', 'memcached'], true);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function remember(int $organizationId, string $key, int $ttlSeconds, Closure $callback): mixed
    {
        if (! self::supportsTags()) {
            return $callback();
        }

        try {
            return Cache::tags([self::tag($organizationId)])->remember($key, $ttlSeconds, $callback);
        } catch (\Throwable) {
            return $callback();
        }
    }

    public static function forget(int $organizationId, string $key): bool
    {
        if (! self::supportsTags()) {
            return false;
        }

        try {
            return Cache::tags([self::tag($organizationId)])->forget($key);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function flush(int $organizationId): bool
    {
        if (! self::supportsTags()) {
            return false;
        }

        try {
            return Cache::tags([self::tag($organizationId)])->flush();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function put(int $organizationId, string $key, mixed $value, int $ttlSeconds): bool
    {
        if (! self::supportsTags()) {
            return Cache::put($key, $value, $ttlSeconds);
        }

        try {
            return Cache::tags([self::tag($organizationId)])->put($key, $value, $ttlSeconds);
        } catch (\Throwable) {
            return Cache::put($key, $value, $ttlSeconds);
        }
    }

    public static function pull(int $organizationId, string $key, mixed $default = null): mixed
    {
        if (! self::supportsTags()) {
            return Cache::pull($key, $default);
        }

        try {
            return Cache::tags([self::tag($organizationId)])->pull($key, $default);
        } catch (\Throwable) {
            return Cache::pull($key, $default);
        }
    }

    /** @return list<string> */
    public static function cacheableKeys(): array
    {
        return [
            'erp.capabilities' => 'Per-user ERP capabilities payload (/erp/capabilities)',
            'erp.profiles' => 'Deployment profile definitions (global, long-lived)',
            'accounting.quickbooks_oauth_state' => 'QuickBooks OAuth CSRF state (short-lived, per org)',
        ];
    }
}
