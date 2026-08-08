<?php

namespace App\Services\Auth;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Durable store for login/MFA challenge payloads.
 *
 * Always uses the database cache store so challenges survive Redis outages,
 * CACHE_STORE=array (per-process memory), and multi-node API deployments
 * where default cache is not shared.
 */
class AuthChallengeCache
{
    public static function store(): Repository
    {
        return Cache::store('database');
    }

    public static function get(string $key): mixed
    {
        return self::store()->get($key);
    }

    public static function put(string $key, mixed $value, mixed $ttl): bool
    {
        return self::store()->put($key, $value, $ttl);
    }

    public static function forget(string $key): bool
    {
        return self::store()->forget($key);
    }
}
