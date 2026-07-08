<?php

namespace Tests\Unit;

use App\Support\EnvironmentSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnvironmentSettingsTest extends TestCase
{
    #[DataProvider('databaseResolutionProvider')]
    public function test_value_resolves_scoped_database_credentials(
        string $appEnv,
        array $env,
        string $key,
        mixed $default,
        mixed $expected,
    ): void {
        $originalEnv = $_ENV;
        $originalServer = $_SERVER;

        try {
            config(['app.env' => $appEnv]);
            $_ENV = array_merge($_ENV, $env);
            $_SERVER = array_merge($_SERVER, $env);

            foreach ($env as $name => $value) {
                putenv("{$name}={$value}");
            }

            $this->assertSame($expected, EnvironmentSettings::value($key, $default));
        } finally {
            $_ENV = $originalEnv;
            $_SERVER = $originalServer;
        }
    }

    public static function databaseResolutionProvider(): array
    {
        return [
            'testing uses test database' => [
                'testing',
                ['DB_DATABASE_TEST' => 'pos_erp_test'],
                'DB_DATABASE',
                'fallback_db',
                'pos_erp_test',
            ],
            'testing inherits local username when test username unset' => [
                'testing',
                ['DB_USERNAME_LOCAL' => 'steve'],
                'DB_USERNAME',
                'root',
                'steve',
            ],
            'testing does not inherit local database name' => [
                'testing',
                ['DB_DATABASE_LOCAL' => 'pos_erp'],
                'DB_DATABASE',
                'pos_erp_test',
                'pos_erp_test',
            ],
            'local uses local database' => [
                'local',
                ['DB_DATABASE_LOCAL' => 'pos_erp'],
                'DB_DATABASE',
                'fallback_db',
                'pos_erp',
            ],
            'production uses production database' => [
                'production',
                ['DB_DATABASE_PRODUCTION' => 'centrix_erp'],
                'DB_DATABASE',
                'fallback_db',
                'centrix_erp',
            ],
        ];
    }

    public function test_guard_refuses_production_database_during_testing(): void
    {
        config(['app.env' => 'testing']);
        putenv('DB_DATABASE_TEST=pos_erp_test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('centrix_erp');

        EnvironmentSettings::guardTestingDatabaseIsolated('centrix_erp');
    }

    public function test_guard_refuses_local_dev_database_during_testing(): void
    {
        config(['app.env' => 'testing']);
        putenv('DB_DATABASE_TEST=pos_erp_test');
        putenv('DB_DATABASE_LOCAL=pos_erp');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pos_erp');

        EnvironmentSettings::guardTestingDatabaseIsolated('pos_erp');
    }

    public function test_guard_allows_dedicated_test_database(): void
    {
        config(['app.env' => 'testing']);
        putenv('DB_DATABASE_TEST=pos_erp_test');
        putenv('DB_HOST_TEST=127.0.0.1');

        EnvironmentSettings::guardTestingDatabaseIsolated('pos_erp_test');

        $this->addToAssertionCount(1);
    }
}
