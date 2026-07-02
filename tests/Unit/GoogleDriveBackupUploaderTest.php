<?php

namespace Tests\Unit;

use App\Services\Backup\GoogleDriveBackupUploader;
use Tests\TestCase;

class GoogleDriveBackupUploaderTest extends TestCase
{
    public function test_is_configured_with_inline_json_credentials(): void
    {
        config([
            'backup.google_drive.enabled' => true,
            'backup.google_drive.folder_id' => 'folder-123',
            'backup.google_drive.credentials_json' => json_encode([
                'type' => 'service_account',
                'project_id' => 'test',
                'private_key_id' => 'key-id',
                'private_key' => "-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----\n",
                'client_email' => 'backup@test.iam.gserviceaccount.com',
                'client_id' => '123',
            ]),
            'backup.google_drive.credentials' => '/does/not/exist.json',
        ]);

        $uploader = app(GoogleDriveBackupUploader::class);

        if (! class_exists(\Google\Client::class)) {
            $this->markTestSkipped('google/apiclient is not installed.');
        }

        config(['backup.google_drive.verify_folder_access' => false]);

        $this->assertTrue($uploader->isConfigured());
        $this->assertTrue($uploader->isEnabled());
        $diagnostics = $uploader->diagnostics();
        $this->assertSame('backup@test.iam.gserviceaccount.com', $diagnostics['service_account_email']);
        $this->assertTrue($diagnostics['upload_ready']);
    }

    public function test_diagnostics_report_missing_enabled_flag(): void
    {
        config([
            'backup.google_drive.enabled' => false,
            'backup.google_drive.folder_id' => 'folder-123',
            'backup.google_drive.credentials_json' => json_encode([
                'type' => 'service_account',
                'client_email' => 'backup@test.iam.gserviceaccount.com',
            ]),
        ]);

        $uploader = app(GoogleDriveBackupUploader::class);
        config(['backup.google_drive.verify_folder_access' => false]);
        $diagnostics = $uploader->diagnostics();

        $this->assertFalse($diagnostics['upload_ready']);
        $this->assertContains('Set BACKUP_GOOGLE_DRIVE_ENABLED=true on the API server.', $diagnostics['issues']);
    }

    public function test_is_not_configured_when_credentials_missing(): void
    {
        config([
            'backup.google_drive.folder_id' => 'folder-123',
            'backup.google_drive.credentials_json' => '',
            'backup.google_drive.credentials' => '',
        ]);

        $uploader = app(GoogleDriveBackupUploader::class);

        $this->assertFalse($uploader->isConfigured());
    }

    public function test_humanize_drive_error_for_missing_folder(): void
    {
        config(['backup.google_drive.verify_folder_access' => false]);
        $uploader = app(GoogleDriveBackupUploader::class);
        $message = $uploader->humanizeDriveErrorForUser('File not found: abc123.');

        $this->assertStringContainsString('Share', $message);
        $this->assertStringContainsString('Editor', $message);
    }
}
