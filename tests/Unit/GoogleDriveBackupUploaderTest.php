<?php

namespace Tests\Unit;

use App\Services\Backup\GoogleDriveBackupUploader;
use Tests\TestCase;

class GoogleDriveBackupUploaderTest extends TestCase
{
    public function test_is_configured_with_inline_json_credentials(): void
    {
        config([
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

        $this->assertTrue($uploader->isConfigured());
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
}
