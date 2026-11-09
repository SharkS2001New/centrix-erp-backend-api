<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fast safety gate: destructive Artisan commands must stay blocked against
 * non-test databases (including local centrix_erp and production).
 */
class DestructiveCommandsGuardTest extends TestCase
{
    public function test_migrate_fresh_is_blocked_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['database.allow_destructive_commands' => false]);

        (new \App\Providers\AppServiceProvider($this->app))->boot();

        $exitCode = Artisan::call('migrate:fresh', ['--force' => true]);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('prohibited', strtolower(Artisan::output()));

        DB::prohibitDestructiveCommands(false);
    }

    public function test_db_wipe_is_blocked_against_local_non_test_database(): void
    {
        $this->app['env'] = 'local';
        config([
            'database.allow_destructive_commands' => false,
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'centrix_erp',
        ]);

        (new \App\Providers\AppServiceProvider($this->app))->boot();

        $exitCode = Artisan::call('db:wipe', ['--force' => true]);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('prohibited', strtolower(Artisan::output()));

        DB::prohibitDestructiveCommands(false);
    }
}
