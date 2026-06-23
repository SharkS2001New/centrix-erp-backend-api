<?php

namespace App\Services\Backup;

use Google\Client as GoogleClient;
use Google\Http\MediaFileUpload;
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
        if (! class_exists(GoogleClient::class)) {
            return false;
        }

        $folderId = trim((string) config('backup.google_drive.folder_id', ''));

        return $folderId !== '' && $this->resolveAuthConfig() !== null;
    }

    /**
     * @return array{
     *     enabled: bool,
     *     configured: bool,
     *     upload_ready: bool,
     *     service_account_email: string|null,
     *     folder_id: string|null,
     *     issues: list<string>,
     * }
     */
    public function diagnostics(): array
    {
        $issues = [];
        $enabledFlag = (bool) config('backup.google_drive.enabled', false);
        $folderId = trim((string) config('backup.google_drive.folder_id', ''));
        $authConfig = $this->resolveAuthConfig();
        $serviceAccountEmail = $this->serviceAccountEmailFromConfig($authConfig);

        if (! class_exists(GoogleClient::class)) {
            $issues[] = 'Google API client package is not installed on the API server.';
        }

        if (! $enabledFlag) {
            $issues[] = 'Set BACKUP_GOOGLE_DRIVE_ENABLED=true on the API server.';
        }

        if ($folderId === '') {
            $issues[] = 'Set BACKUP_GOOGLE_DRIVE_FOLDER_ID to the target Drive folder ID.';
        }

        if ($authConfig === null) {
            $issues[] = 'Set BACKUP_GOOGLE_DRIVE_CREDENTIALS_JSON or BACKUP_GOOGLE_DRIVE_CREDENTIALS (service account JSON).';
        }

        $configured = $folderId !== '' && $authConfig !== null && class_exists(GoogleClient::class);
        $uploadReady = $enabledFlag && $configured;

        $setupNotes = [];
        if ($serviceAccountEmail && $folderId !== '' && $uploadReady) {
            $setupNotes[] = 'The Drive folder must be shared with '.$serviceAccountEmail.' as Editor.';
        }

        return [
            'enabled' => $enabledFlag && $configured,
            'configured' => $configured,
            'upload_ready' => $uploadReady,
            'service_account_email' => $serviceAccountEmail,
            'folder_id' => $folderId !== '' ? $folderId : null,
            'issues' => array_values(array_unique($issues)),
            'setup_notes' => $setupNotes,
        ];
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
        $mimeType = str_ends_with($filename, '.gz') ? 'application/gzip' : 'application/sql';
        $fileSize = (int) filesize($absolutePath);

        $metadata = new DriveFile([
            'name' => $filename,
            'parents' => [$folderId],
        ]);

        $createParams = [
            'supportsAllDrives' => true,
            'fields' => 'id,name,webViewLink',
        ];

        $client = $service->getClient();
        $client->setDefer(true);
        $request = $service->files->create($metadata, $createParams);

        $chunkSize = 5 * 1024 * 1024;
        $media = new MediaFileUpload(
            $client,
            $request,
            $mimeType,
            null,
            true,
            $chunkSize,
        );
        $media->setFileSize($fileSize);

        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not read backup file for Google Drive upload.');
        }

        $status = false;
        try {
            while (! $status && ! feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) {
                    throw new \RuntimeException('Could not read backup file chunk for Google Drive upload.');
                }
                $status = $media->nextChunk($chunk);
            }
        } finally {
            fclose($handle);
            $client->setDefer(false);
        }

        /** @var DriveFile $file */
        $file = $status;

        Log::info('Database backup uploaded to Google Drive', [
            'filename' => $filename,
            'file_id' => $file->getId(),
            'size_bytes' => $fileSize,
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
            $diagnostics = $uploader->diagnostics();

            return [
                'enabled' => $diagnostics['upload_ready'],
                'configured' => $diagnostics['configured'],
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
            $decoded = $this->decodeCredentialsJson($json);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $path = trim((string) config('backup.google_drive.credentials', ''));
        if ($path === '') {
            return null;
        }

        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $decoded = $this->decodeCredentialsJson($contents);

        return is_array($decoded) ? $decoded : $path;
    }

    /** @return array<string, mixed>|null */
    protected function decodeCredentialsJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (! str_starts_with($raw, '{')) {
            $fromBase64 = base64_decode($raw, true);
            if (is_string($fromBase64) && $fromBase64 !== '') {
                $decoded = json_decode($fromBase64, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>|string|null  $authConfig */
    protected function serviceAccountEmailFromConfig(array|string|null $authConfig): ?string
    {
        if (is_array($authConfig)) {
            $email = trim((string) ($authConfig['client_email'] ?? ''));

            return $email !== '' ? $email : null;
        }

        if (! is_string($authConfig) || ! is_readable($authConfig)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($authConfig), true);

        if (! is_array($decoded)) {
            return null;
        }

        $email = trim((string) ($decoded['client_email'] ?? ''));

        return $email !== '' ? $email : null;
    }
}
