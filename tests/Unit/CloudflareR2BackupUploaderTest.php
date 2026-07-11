<?php

namespace Tests\Unit;

use App\Services\Backup\BackupR2SettingsResolver;
use App\Services\Backup\CloudflareR2BackupUploader;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CloudflareR2BackupUploaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $org = BackupR2SettingsResolver::platformOrganization(refresh: true);
        if ($org) {
            $moduleSettings = $org->module_settings ?? [];
            unset($moduleSettings[BackupR2SettingsResolver::MODULE_KEY]);
            $org->update(['module_settings' => $moduleSettings]);
            BackupR2SettingsResolver::platformOrganization(refresh: true);
        }
    }

    public function test_is_enabled_when_fully_configured(): void
    {
        config([
            'backup.r2.enabled' => true,
            'backup.r2.key' => 'akid',
            'backup.r2.secret' => 'secret',
            'backup.r2.bucket' => 'centrix-backups',
            'backup.r2.endpoint' => 'https://account.r2.cloudflarestorage.com',
        ]);

        $uploader = app(CloudflareR2BackupUploader::class);

        $this->assertTrue($uploader->isConfigured());
        $this->assertTrue($uploader->isEnabled());
        $this->assertTrue($uploader->diagnostics()['upload_ready']);
    }

    public function test_diagnostics_reports_disabled_flag(): void
    {
        config([
            'backup.r2.enabled' => false,
            'backup.r2.key' => 'akid',
            'backup.r2.secret' => 'secret',
            'backup.r2.bucket' => 'centrix-backups',
            'backup.r2.endpoint' => 'https://account.r2.cloudflarestorage.com',
        ]);

        $diagnostics = app(CloudflareR2BackupUploader::class)->diagnostics();

        $this->assertFalse($diagnostics['upload_ready']);
        $this->assertContains('Enable Cloudflare R2 upload in Platform → Database backups settings.', $diagnostics['issues']);
    }

    public function test_diagnostics_reports_missing_credentials(): void
    {
        config([
            'backup.r2.enabled' => true,
            'backup.r2.key' => '',
            'backup.r2.secret' => '',
            'backup.r2.bucket' => '',
            'backup.r2.endpoint' => '',
        ]);

        $diagnostics = app(CloudflareR2BackupUploader::class)->diagnostics();

        $this->assertFalse($diagnostics['configured']);
        $this->assertContains('Set Access key ID in Platform → Database backups settings.', $diagnostics['issues']);
        $this->assertContains('Set Secret access key in Platform → Database backups settings.', $diagnostics['issues']);
        $this->assertContains('Set Bucket name in Platform → Database backups settings.', $diagnostics['issues']);
        $this->assertContains('Set Endpoint URL in Platform → Database backups settings.', $diagnostics['issues']);
    }

    public function test_upload_puts_object_on_r2_disk(): void
    {
        config([
            'backup.r2.enabled' => true,
            'backup.r2.disk' => 'r2',
            'backup.r2.key' => 'akid',
            'backup.r2.secret' => 'secret',
            'backup.r2.bucket' => 'centrix-backups',
            'backup.r2.endpoint' => 'https://account.r2.cloudflarestorage.com',
            'backup.r2.prefix' => 'backups/database',
            'backup.r2.public_url' => 'https://backups.example.com',
            'filesystems.disks.r2' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/r2'),
                'throw' => true,
            ],
        ]);

        Storage::fake('r2');

        $source = tempnam(sys_get_temp_dir(), 'backup');
        file_put_contents($source, 'sql-dump');

        $result = app(CloudflareR2BackupUploader::class)->upload($source, 'pos_erp_2026-07-10.sql.gz');

        Storage::disk('r2')->assertExists('backups/database/pos_erp_2026-07-10.sql.gz');
        $this->assertSame('backups/database/pos_erp_2026-07-10.sql.gz', $result['file_id']);
        $this->assertSame('https://backups.example.com/backups/database/pos_erp_2026-07-10.sql.gz', $result['web_view_link']);

        @unlink($source);
    }

    public function test_connection_probe_is_removed_and_upload_test_keeps_file(): void
    {
        config([
            'backup.r2.enabled' => true,
            'backup.r2.disk' => 'r2',
            'backup.r2.key' => 'akid',
            'backup.r2.secret' => 'secret',
            'backup.r2.bucket' => 'centrix-backups',
            'backup.r2.endpoint' => 'https://account.r2.cloudflarestorage.com',
            'backup.r2.prefix' => 'backups/database',
            'backup.r2.public_url' => 'https://backups.example.com',
            'filesystems.disks.r2' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/r2'),
                'throw' => true,
            ],
        ]);

        Storage::fake('r2');
        $uploader = app(CloudflareR2BackupUploader::class);

        $connection = $uploader->testConnection();
        $this->assertTrue($connection['ok']);
        $this->assertSame([], Storage::disk('r2')->allFiles());

        $upload = $uploader->testUpload();
        $this->assertTrue($upload['ok']);
        Storage::disk('r2')->assertExists($upload['file_id']);
        $this->assertStringContainsString('connection-tests/', $upload['file_id']);
    }
}
