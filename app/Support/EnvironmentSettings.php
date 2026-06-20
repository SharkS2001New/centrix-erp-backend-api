<?php

namespace App\Support;

class EnvironmentSettings
{
    public static function isProduction(): bool
    {
        return env('APP_ENV') === 'production';
    }

    /**
     * Resolve an env var for the active environment.
     * Checks KEY_LOCAL / KEY_PRODUCTION first, then KEY.
     */
    public static function value(string $key, mixed $default = null): mixed
    {
        $suffix = self::isProduction() ? 'PRODUCTION' : 'LOCAL';
        $scoped = env("{$key}_{$suffix}");

        if ($scoped !== null && $scoped !== '') {
            return $scoped;
        }

        return env($key, $default);
    }
}
