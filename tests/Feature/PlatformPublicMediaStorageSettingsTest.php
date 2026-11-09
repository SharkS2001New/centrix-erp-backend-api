<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Backup\BackupR2SettingsResolver;
use App\Services\Storage\PublicMediaR2SettingsResolver;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformPublicMediaStorageSettingsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_save_media_r2_with_own_credentials(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $this->putJson('/api/v1/admin/public-media-storage-settings', [
            'enabled' => true,
            'use_backup_credentials' => false,
            'access_key_id' => 'media-akid',
            'secret_access_key' => 'media-secret',
            'bucket' => 'centrix-media',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'region' => 'auto',
        ])
            ->assertOk()
            ->assertJsonPath('settings.enabled', true)
            ->assertJsonPath('settings.use_backup_credentials', false)
            ->assertJsonPath('settings.bucket', 'centrix-media')
            ->assertJsonPath('settings.secret_access_key_set', true)
            ->assertJsonMissingPath('settings.secret_access_key')
            ->assertJsonPath('effective.ready', true);

        $resolved = PublicMediaR2SettingsResolver::resolve();
        $this->assertSame('media-secret', $resolved['secret_access_key']);
        $this->assertSame('centrix-media', $resolved['bucket']);
    }

    public function test_media_settings_can_reuse_backup_api_credentials(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $this->putJson('/api/v1/admin/database-backup-settings', [
            'enabled' => true,
            'access_key_id' => 'backup-akid',
            'secret_access_key' => 'backup-secret',
            'bucket' => 'centrix-backups',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'region' => 'auto',
            'prefix' => 'backups/database',
        ])->assertOk();

        $this->putJson('/api/v1/admin/public-media-storage-settings', [
            'enabled' => true,
            'use_backup_credentials' => true,
            'bucket' => 'centrix-media',
        ])
            ->assertOk()
            ->assertJsonPath('settings.use_backup_credentials', true)
            ->assertJsonPath('settings.bucket', 'centrix-media')
            ->assertJsonPath('effective.ready', true);

        $resolved = PublicMediaR2SettingsResolver::resolve();
        $this->assertSame('backup-akid', $resolved['access_key_id']);
        $this->assertSame('backup-secret', $resolved['secret_access_key']);
        $this->assertSame('https://account.r2.cloudflarestorage.com', $resolved['endpoint']);
        $this->assertSame('centrix-media', $resolved['bucket']);
        $this->assertSame('centrix-backups', BackupR2SettingsResolver::resolve()['bucket']);
    }

    public function test_non_super_admin_cannot_update_media_r2_settings(): void
    {
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());

        $this->putJson('/api/v1/admin/public-media-storage-settings', [
            'enabled' => true,
        ])->assertForbidden();

        $this->postJson('/api/v1/admin/public-media-storage-settings/test-connection')
            ->assertForbidden();
    }
}
