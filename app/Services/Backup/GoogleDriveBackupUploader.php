<?php

namespace App\Services\Backup;

use Google\Client as GoogleClient;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;

class GoogleDriveBackupUploader
{
    public function isEnabled(): bool
    {
        return (bool) config('backup.google_drive.enabled', false) && $this->isConfigured();
    }

    public function isConfigured(): bool
    {
        $credentials = trim((string) config('backup.google_drive.credentials', ''));
        $folderId = trim((string) config('backup.google_drive.folder_id', ''));

        return $credentials !== ''
            && is_file($credentials)
            && $folderId !== '';
    }

    /**
     * @return array{file_id: string, name: string, web_view_link: string|null}
     */
    public function upload(string $absolutePath, string $filename): array
    {
        if (! is_file($absolutePath)) {
            throw new \InvalidArgumentException('Backup file was not found.');
        }

        $service = $this->driveService();
        $folderId = (string) config('backup.google_drive.folder_id');

        $metadata = new DriveFile([
            'name' => $filename,
            'parents' => [$folderId],
        ]);

        $mimeType = str_ends_with($filename, '.gz')
            ? 'application/gzip'
            : 'application/sql';

        $file = $service->files->create($metadata, [
            'data' => file_get_contents($absolutePath),
            'mimeType' => $mimeType,
            'uploadType' => 'multipart',
            'fields' => 'id,name,webViewLink',
        ]);

        Log::info('Database backup uploaded to Google Drive', [
            'filename' => $filename,
            'file_id' => $file->getId(),
        ]);

        return [
            'file_id' => (string) $file->getId(),
            'name' => (string) $file->getName(),
            'web_view_link' => $file->getWebViewLink(),
        ];
    }

    protected function driveService(): Drive
    {
        $client = new GoogleClient;
        $client->setAuthConfig((string) config('backup.google_drive.credentials'));
        $client->setScopes([Drive::DRIVE_FILE]);

        return new Drive($client);
    }
}
