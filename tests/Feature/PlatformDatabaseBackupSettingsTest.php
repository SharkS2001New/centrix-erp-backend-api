<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Backup\BackupR2SettingsResolver;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformDatabaseBackupSettingsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_save_r2_settings(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $response = $this->putJson('/api/v1/admin/database-backup-settings', [
            'enabled' => true,
            'access_key_id' => 'akid-test',
            'secret_access_key' => 'secret-test-value',
            'bucket' => 'centrix-backups',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'region' => 'auto',
            'prefix' => 'backups/database',
            'public_url' => 'https://backups.example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('settings.enabled', true)
            ->assertJsonPath('settings.access_key_id', 'akid-test')
            ->assertJsonPath('settings.bucket', 'centrix-backups')
            ->assertJsonPath('settings.secret_access_key_set', true)
            ->assertJsonMissingPath('settings.secret_access_key')
            ->assertJsonPath('effective.upload_ready', true)
            ->assertJsonPath('effective.source', 'platform');

        $resolved = BackupR2SettingsResolver::resolve();
        $this->assertSame('secret-test-value', $resolved['secret_access_key']);
    }

    public function test_blank_secret_keeps_existing_value(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $this->putJson('/api/v1/admin/database-backup-settings', [
            'enabled' => true,
            'access_key_id' => 'akid-test',
            'secret_access_key' => 'keep-me',
            'bucket' => 'centrix-backups',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
        ])->assertOk();

        $this->putJson('/api/v1/admin/database-backup-settings', [
            'enabled' => true,
            'access_key_id' => 'akid-test-2',
            'secret_access_key' => '',
            'bucket' => 'centrix-backups',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
        ])->assertOk()
            ->assertJsonPath('settings.access_key_id', 'akid-test-2')
            ->assertJsonPath('settings.secret_access_key_set', true);

        $this->assertSame('keep-me', BackupR2SettingsResolver::resolve()['secret_access_key']);
    }

    public function test_super_admin_can_test_r2_connection_and_upload(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        config([
            'backup.r2.disk' => 'r2',
            'filesystems.disks.r2' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/r2-settings-test'),
                'throw' => true,
            ],
        ]);
        Storage::fake('r2');

        $this->putJson('/api/v1/admin/database-backup-settings', [
            'enabled' => true,
            'access_key_id' => 'akid-test',
            'secret_access_key' => 'secret-test',
            'bucket' => 'centrix-backups',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'prefix' => 'backups/database',
            'public_url' => 'https://backups.example.com',
        ])->assertOk();

        $this->postJson('/api/v1/admin/database-backup-settings/test-connection')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('bucket', 'centrix-backups');

        $upload = $this->postJson('/api/v1/admin/database-backup-settings/test-upload')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('bucket', 'centrix-backups');

        $fileId = $upload->json('file_id');
        $this->assertNotEmpty($fileId);
        Storage::disk('r2')->assertExists($fileId);
    }

    public function test_non_super_admin_cannot_update_r2_settings(): void
    {
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());

        $this->putJson('/api/v1/admin/database-backup-settings', [
            'enabled' => true,
        ])->assertForbidden();

        $this->postJson('/api/v1/admin/database-backup-settings/test-connection')
            ->assertForbidden();
        $this->postJson('/api/v1/admin/database-backup-settings/test-upload')
            ->assertForbidden();
    }
}
