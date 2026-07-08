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
     * Abort when PHPUnit/artisan test would touch production or local dev data stores.
     *
     * Tests must run against DB_DATABASE_TEST on localhost only.
     */
    public static function guardTestingDatabaseIsolated(?string $databaseName): void
    {
        if (! self::isTesting()) {
            return;
        }

        $databaseName = trim((string) $databaseName);
        $expected = trim((string) (self::envRaw('DB_DATABASE_TEST') ?: 'pos_erp_test'));

        if ($databaseName === '') {
            throw new \RuntimeException(
                'Tests require DB_DATABASE_TEST (e.g. pos_erp_test). Never run tests against production or local dev databases.',
            );
        }

        $forbidden = array_unique(array_filter([
            self::envRaw('DB_DATABASE_PRODUCTION'),
            'centrix_erp',
            self::envRaw('DB_DATABASE_LOCAL') ?: 'pos_erp',
        ], static fn ($value) => is_string($value) && $value !== ''));

        foreach ($forbidden as $forbiddenName) {
            if (strcasecmp($databaseName, $forbiddenName) === 0) {
                throw new \RuntimeException(
                    "Refusing to run tests against database [{$databaseName}]. "
                    ."Use local test database [{$expected}] on 127.0.0.1 — not production or pos_erp dev data.",
                );
            }
        }

        if (strcasecmp($databaseName, $expected) !== 0) {
            throw new \RuntimeException(
                "Test database [{$databaseName}] does not match DB_DATABASE_TEST [{$expected}].",
            );
        }

        $host = strtolower(trim((string) self::value('DB_HOST', '127.0.0.1')));
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new \RuntimeException(
                "Tests must use a local MySQL host (127.0.0.1), not [{$host}].",
            );
        }
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

    private static function envRaw(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
