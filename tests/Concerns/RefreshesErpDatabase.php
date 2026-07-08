<?php

namespace Tests\Concerns;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/**
 * MySQL schema load uses DDL/triggers that break nested savepoints from RefreshDatabase transactions.
 *
 * migrate:fresh runs once per PHPUnit process; DemoDataSeeder truncates and reseeds between tests.
 */
trait RefreshesErpDatabase
{
    use RefreshDatabase;

    /** @var array<int, string|null> */
    protected array $connectionsToTransact = [];

    public function refreshDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->dropAllViews();
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            $this->app[Kernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        }

        $this->seed(DemoDataSeeder::class);
    }

    protected function dropAllViews(): void
    {
        $database = DB::getDatabaseName();
        if ($database === null || $database === '') {
            return;
        }

        $views = DB::select(
            'SELECT table_name AS view_name FROM information_schema.views WHERE table_schema = ?',
            [$database],
        );

        if ($views === []) {
            return;
        }

        DB::unprepared('SET FOREIGN_KEY_CHECKS=0');
        foreach ($views as $view) {
            $name = (string) ($view->view_name ?? '');
            if ($name === '') {
                continue;
            }
            $escaped = str_replace('`', '``', $name);
            DB::unprepared("DROP VIEW IF EXISTS `{$escaped}`");
        }
        DB::unprepared('SET FOREIGN_KEY_CHECKS=1');
    }
}
