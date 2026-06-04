<?php

namespace Tests\Concerns;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * MySQL schema load uses DDL/triggers that break nested savepoints from RefreshDatabase transactions.
 */
trait RefreshesErpDatabase
{
    use RefreshDatabase;

    /** @var array<int, string|null> */
    protected array $connectionsToTransact = [];

    public function refreshDatabase(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);
        $this->seed(DemoDataSeeder::class);
    }
}
