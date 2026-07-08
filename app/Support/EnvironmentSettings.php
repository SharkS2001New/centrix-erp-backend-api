<?php

namespace App\Support;

class EnvironmentSettings
{
    public static function isProduction(): bool
    {
        return self::appEnv() === 'production';
    }

    public static function isTesting(): bool
    {
        return self::appEnv() === 'testing';
    }

    public static function appEnv(): string
    {
        return (string) config('app.env', env('APP_ENV', 'production'));
    }

    /**
     * Resolve an env var for the active environment.
     * Checks KEY_TEST / KEY_LOCAL / KEY_PRODUCTION first, then KEY.
     */
    public static function value(string $key, mixed $default = null): mixed
    {
        $suffix = match (true) {
            self::isProduction() => 'PRODUCTION',
            self::isTesting() => 'TEST',
            default => 'LOCAL',
        };

        $scoped = env("{$key}_{$suffix}");
        if ($scoped !== null && $scoped !== '') {
            return $scoped;
        }

        // Testing: reuse local connection settings when TEST-specific vars are unset,
        // but never inherit DB_DATABASE_LOCAL (tests must target a dedicated database).
        if ($suffix === 'TEST' && $key !== 'DB_DATABASE') {
            $localScoped = env("{$key}_LOCAL");
            if ($localScoped !== null && $localScoped !== '') {
                return $localScoped;
            }
        }

        return env($key, $default);
    }
}
