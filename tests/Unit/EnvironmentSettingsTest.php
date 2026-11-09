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
        array $clear,
        string $key,
        mixed $default,
        mixed $expected,
    ): void {
        $originalEnv = $_ENV;
        $originalServer = $_SERVER;
        $originalPutenv = [];

        foreach (array_unique(array_merge(array_keys($env), $clear)) as $name) {
            $originalPutenv[$name] = getenv($name);
        }

        try {
            config(['app.env' => $appEnv]);

            foreach ($clear as $name) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }

            $_ENV = array_merge($_ENV, $env);
            $_SERVER = array_merge($_SERVER, $env);
            foreach ($env as $name => $value) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }

            $this->assertSame($expected, EnvironmentSettings::value($key, $default));
        } finally {
            $_ENV = $originalEnv;
            $_SERVER = $originalServer;
            foreach ($originalPutenv as $name => $value) {
                if ($value === false) {
                    putenv($name);
                } else {
                    putenv("{$name}={$value}");
                }
            }
        }
    }

    public static function databaseResolutionProvider(): array
    {
        return [
            'testing uses test database' => [
                'testing',
                ['DB_DATABASE_TEST' => 'pos_erp_test'],
                [],
                'DB_DATABASE',
                'fallback_db',
                'pos_erp_test',
            ],
            'testing inherits local username when test username unset' => [
                'testing',
                ['DB_USERNAME_LOCAL' => 'steve'],
                // CI / phpunit may inject DB_USERNAME_TEST=root — clear so LOCAL can win.
                ['DB_USERNAME_TEST', 'DB_USERNAME'],
                'DB_USERNAME',
                'root',
                'steve',
            ],
            'testing does not inherit local database name' => [
                'testing',
                ['DB_DATABASE_LOCAL' => 'pos_erp'],
                ['DB_DATABASE_TEST', 'DB_DATABASE'],
                'DB_DATABASE',
                'pos_erp_test',
                'pos_erp_test',
            ],
            'local uses local database' => [
                'local',
                ['DB_DATABASE_LOCAL' => 'pos_erp'],
                [],
                'DB_DATABASE',
                'fallback_db',
                'pos_erp',
            ],
            'production uses production database' => [
                'production',
                ['DB_DATABASE_PRODUCTION' => 'centrix_erp'],
                [],
                'DB_DATABASE',
                'fallback_db',
                'centrix_erp',
            ],
        ];
    }
}
