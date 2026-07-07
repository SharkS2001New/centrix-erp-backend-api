<?php

namespace Tests\Concerns;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

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
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            $this->app[Kernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        }

        $this->seed(DemoDataSeeder::class);
    }
}
