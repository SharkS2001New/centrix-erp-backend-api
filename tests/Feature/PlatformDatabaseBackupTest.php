<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformDatabaseBackupTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_list_database_backups(): void
    {
        config([
            'backup.disk' => 'local',
            'backup.path' => 'backups/testing-platform',
        ]);

        Storage::disk('local')->makeDirectory('backups/testing-platform');
        Storage::disk('local')->put('backups/testing-platform/pos_erp_2026-06-20.sql.gz', 'dump');

        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $response = $this->getJson('/api/v1/admin/database-backups');

        $response->assertOk()
            ->assertJsonPath('google_drive_enabled', false)
            ->assertJsonFragment(['filename' => 'pos_erp_2026-06-20.sql.gz']);

        Storage::disk('local')->deleteDirectory('backups/testing-platform');
    }

    public function test_super_admin_can_download_database_backup(): void
    {
        config([
            'backup.disk' => 'local',
            'backup.path' => 'backups/testing-platform',
        ]);

        Storage::disk('local')->makeDirectory('backups/testing-platform');
        Storage::disk('local')->put('backups/testing-platform/pos_erp_2026-06-20.sql.gz', 'dump-content');

        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $response = $this->get('/api/v1/admin/database-backups/pos_erp_2026-06-20.sql.gz/download');

        $response->assertOk();
        $this->assertStringContainsString('dump-content', $response->getContent());

        Storage::disk('local')->deleteDirectory('backups/testing-platform');
    }

    public function test_non_super_admin_cannot_access_database_backups(): void
    {
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());

        $this->getJson('/api/v1/admin/database-backups')->assertForbidden();
    }

    public function test_super_admin_can_trigger_manual_backup(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $mock = Mockery::mock(DatabaseBackupService::class);
        $mock->shouldReceive('runBackupCycle')
            ->once()
            ->with(true, true, true)
            ->andReturn([
                'backup' => [
                    'disk' => 'local',
                    'relative_path' => 'backups/database/pos_erp_2026-06-20.sql.gz',
                    'absolute_path' => storage_path('app/private/backups/database/pos_erp_2026-06-20.sql.gz'),
                    'filename' => 'pos_erp_2026-06-20.sql.gz',
                    'size_bytes' => 128,
                    'compressed' => true,
                    'driver' => 'mysql',
                    'database' => 'pos_erp',
                    'created_at' => now()->toIso8601String(),
                ],
                'google_drive' => null,
                'email_sent' => false,
                'pruned' => 0,
            ]);

        $this->app->instance(DatabaseBackupService::class, $mock);

        $this->postJson('/api/v1/admin/database-backups')
            ->assertCreated()
            ->assertJsonPath('data.filename', 'pos_erp_2026-06-20.sql.gz');
    }

    public function test_backup_failure_returns_actionable_detail(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $mock = Mockery::mock(DatabaseBackupService::class);
        $mock->shouldReceive('runBackupCycle')
            ->once()
            ->andThrow(new \App\Services\Backup\DatabaseBackupException(
                'Could not reach the database server from the API pod.',
                'mysqldump_failed',
            ));

        $this->app->instance(DatabaseBackupService::class, $mock);

        $this->postJson('/api/v1/admin/database-backups')
            ->assertStatus(500)
            ->assertJsonPath('code', 'mysqldump_failed')
            ->assertJsonPath('detail', 'Could not reach the database server from the API pod.');
    }
}
