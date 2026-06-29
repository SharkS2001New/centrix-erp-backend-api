<?php

namespace App\Services\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;

class OrganizationCache
{
    private const CAPABILITIES_VERSION_SUFFIX = ':capabilities:version';

    public static function tag(int $organizationId): string
    {
        return 'org:'.$organizationId;
    }

    public static function supportsTags(): bool
    {
        return in_array(config('cache.default'), ['redis', 'memcached'], true);
    }

    public static function capabilitiesVersion(int $organizationId): int
    {
        return max(1, (int) Cache::get(self::tag($organizationId).self::CAPABILITIES_VERSION_SUFFIX, 1));
    }

    /** Per-user capabilities payload key (scoped to org + generation version). */
    public static function capabilitiesUserKey(int $organizationId, int $userId): string
    {
        return 'capabilities:v'.self::capabilitiesVersion($organizationId).':user:'.$userId;
    }

    /**
     * Drop all cached capabilities for an organization. Next request rebuilds fresh entries.
     */
    public static function invalidateCapabilities(int $organizationId): void
    {
        $versionKey = self::tag($organizationId).self::CAPABILITIES_VERSION_SUFFIX;
        Cache::put($versionKey, self::capabilitiesVersion($organizationId) + 1, 86400 * 30);

        self::flush($organizationId);

        Cache::forget(self::tag($organizationId).':legacy_archive:connectable');
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
            return Cache::remember(self::tag($organizationId).':'.$key, $ttlSeconds, $callback);
        }

        try {
            return Cache::tags([self::tag($organizationId)])->remember($key, $ttlSeconds, $callback);
        } catch (\Throwable) {
            return Cache::remember(self::tag($organizationId).':'.$key, $ttlSeconds, $callback);
        }
    }

    public static function forget(int $organizationId, string $key): bool
    {
        if (! self::supportsTags()) {
            return Cache::forget(self::tag($organizationId).':'.$key);
        }

        try {
            return Cache::tags([self::tag($organizationId)])->forget($key);
        } catch (\Throwable) {
            return Cache::forget(self::tag($organizationId).':'.$key);
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
            return Cache::put(self::tag($organizationId).':'.$key, $value, $ttlSeconds);
        }

        try {
            return Cache::tags([self::tag($organizationId)])->put($key, $value, $ttlSeconds);
        } catch (\Throwable) {
            return Cache::put(self::tag($organizationId).':'.$key, $value, $ttlSeconds);
        }
    }

    public static function pull(int $organizationId, string $key, mixed $default = null): mixed
    {
        if (! self::supportsTags()) {
            return Cache::pull(self::tag($organizationId).':'.$key, $default);
        }

        try {
            return Cache::tags([self::tag($organizationId)])->pull($key, $default);
        } catch (\Throwable) {
            return Cache::pull(self::tag($organizationId).':'.$key, $default);
        }
    }

    /** @return list<string> */
    public static function cacheableKeys(): array
    {
        return [
            'erp.capabilities' => 'Per-user ERP capabilities (/erp/capabilities), org-scoped generation version',
            'erp.profiles' => 'Deployment profile definitions (global, long-lived)',
            'accounting.quickbooks_oauth_state' => 'QuickBooks OAuth CSRF state (short-lived, per org)',
        ];
    }
}
