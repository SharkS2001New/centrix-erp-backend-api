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
        if (! class_exists(\Google\Client::class)) {
            return false;
        }

        $folderId = trim((string) config('backup.google_drive.folder_id', ''));

        return $folderId !== '' && $this->resolveAuthConfig() !== null;
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

    /** @return array{enabled: bool, configured: bool} */
    public static function status(): array
    {
        try {
            /** @var self $uploader */
            $uploader = app(self::class);

            return [
                'enabled' => $uploader->isEnabled(),
                'configured' => $uploader->isConfigured(),
            ];
        } catch (\Throwable $e) {
            report($e);

            return [
                'enabled' => false,
                'configured' => false,
            ];
        }
    }

    protected function driveService(): Drive
    {
        $authConfig = $this->resolveAuthConfig();
        if ($authConfig === null) {
            throw new \RuntimeException('Google Drive credentials are not configured.');
        }

        $client = new GoogleClient;
        $client->setAuthConfig($authConfig);
        $client->setScopes([Drive::DRIVE_FILE]);

        return new Drive($client);
    }

    /** @return array<string, mixed>|string|null */
    protected function resolveAuthConfig(): array|string|null
    {
        $json = trim((string) config('backup.google_drive.credentials_json', ''));
        if ($json !== '') {
            $decoded = json_decode($json, true);

            return is_array($decoded) ? $decoded : null;
        }

        $path = trim((string) config('backup.google_drive.credentials', ''));
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return $path;
    }
}
