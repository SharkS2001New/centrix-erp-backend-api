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

        if ($folderId === '') {
            return false;
        }

        if ($this->usesOAuth()) {
            return $this->oauthCredentialsComplete();
        }

        return $this->resolveAuthConfig() !== null;
    }

    /**
     * @return array{
     *     enabled: bool,
     *     configured: bool,
     *     upload_ready: bool,
     *     service_account_email: string|null,
     *     auth_mode: string,
     *     folder_id: string|null,
     *     folder_accessible: bool|null,
     *     folder_on_shared_drive: bool|null,
     *     issues: list<string>,
     *     setup_notes: list<string>,
     * }
     */
    public function diagnostics(): array
    {
        $issues = [];
        $enabledFlag = (bool) config('backup.google_drive.enabled', false);
        $folderId = trim((string) config('backup.google_drive.folder_id', ''));
        $authMode = $this->usesOAuth() ? 'oauth' : 'service_account';
        $authConfig = $authMode === 'service_account' ? $this->resolveAuthConfig() : null;
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

        if ($authMode === 'oauth') {
            if (! $this->oauthCredentialsComplete()) {
                $issues[] = 'Set BACKUP_GOOGLE_DRIVE_OAUTH_CLIENT_ID, BACKUP_GOOGLE_DRIVE_OAUTH_CLIENT_SECRET, and BACKUP_GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN (run php artisan erp:authorize-google-drive-backup).';
            }
        } elseif ($authConfig === null) {
            $issues[] = 'Set BACKUP_GOOGLE_DRIVE_CREDENTIALS_JSON or BACKUP_GOOGLE_DRIVE_CREDENTIALS (service account JSON).';
        }

        $configured = $folderId !== ''
            && class_exists(GoogleClient::class)
            && ($authMode === 'oauth' ? $this->oauthCredentialsComplete() : $authConfig !== null);
        $folderAccess = $configured ? $this->folderAccessCheck() : null;

        if ($folderAccess !== null && ! $folderAccess['accessible']) {
            $issues[] = $folderAccess['issue'];
        }

        $uploadReady = $enabledFlag && $configured && ($folderAccess === null || $folderAccess['accessible']);

        $setupNotes = [];
        if ($authMode === 'service_account' && $serviceAccountEmail && $folderId !== '' && $configured) {
            $setupNotes[] = 'Share the target Drive folder with '.$serviceAccountEmail.' as Editor (or add that account to a Shared drive).';
        }
        if ($authMode === 'oauth' && $configured) {
            $setupNotes[] = 'OAuth uploads use your Google account storage (works with personal Gmail folders).';
        }
        if ($folderAccess !== null && $folderAccess['accessible'] && $folderAccess['shared_drive'] === false && $authMode === 'service_account') {
            $issues[] = 'Personal Gmail folders cannot receive service-account uploads (Google storage quota policy). Set BACKUP_GOOGLE_DRIVE_AUTH=oauth and run php artisan erp:authorize-google-drive-backup, or use a Google Workspace Shared drive.';
            $uploadReady = false;
        }

        return [
            'enabled' => $enabledFlag && $configured,
            'configured' => $configured,
            'upload_ready' => $uploadReady,
            'auth_mode' => $authMode,
            'service_account_email' => $serviceAccountEmail,
            'folder_id' => $folderId !== '' ? $folderId : null,
            'folder_accessible' => $folderAccess['accessible'] ?? null,
            'folder_on_shared_drive' => $folderAccess['shared_drive'] ?? null,
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
        $folderAccess = $this->folderAccessCheck($service);
        if ($folderAccess !== null && ! $folderAccess['accessible']) {
            throw new \RuntimeException($folderAccess['issue']);
        }
        if (! $this->usesOAuth() && $folderAccess !== null && $folderAccess['accessible'] && $folderAccess['shared_drive'] === false) {
            throw new \RuntimeException(
                'Personal Gmail folders cannot receive service-account uploads. Set BACKUP_GOOGLE_DRIVE_AUTH=oauth and run php artisan erp:authorize-google-drive-backup.',
            );
        }
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

    public function humanizeDriveErrorForUser(string $raw): string
    {
        return $this->humanizeDriveError($raw, $this->serviceAccountEmailFromConfig($this->resolveAuthConfig()));
    }

    protected function driveService(): Drive
    {
        $client = new GoogleClient;

        if ($this->usesOAuth()) {
            if (! $this->oauthCredentialsComplete()) {
                throw new \RuntimeException('Google Drive OAuth credentials are not configured.');
            }

            $client->setClientId((string) config('backup.google_drive.oauth_client_id'));
            $client->setClientSecret((string) config('backup.google_drive.oauth_client_secret'));
            $client->setAccessType('offline');
            $client->setScopes([Drive::DRIVE_FILE]);
            $token = $client->fetchAccessTokenWithRefreshToken(
                (string) config('backup.google_drive.oauth_refresh_token'),
            );
            if (isset($token['error'])) {
                throw new \RuntimeException('Google Drive OAuth token refresh failed: '
                    .($token['error_description'] ?? $token['error']));
            }
            $client->setAccessToken($token);

            return new Drive($client);
        }

        $authConfig = $this->resolveAuthConfig();
        if ($authConfig === null) {
            throw new \RuntimeException('Google Drive credentials are not configured.');
        }

        $client->setAuthConfig($authConfig);
        // drive.file only sees files this app created; shared backup folders need full drive scope.
        $client->setScopes([Drive::DRIVE]);

        return new Drive($client);
    }

    protected function usesOAuth(): bool
    {
        if (strtolower((string) config('backup.google_drive.auth_mode', 'service_account')) === 'oauth') {
            return true;
        }

        return trim((string) config('backup.google_drive.oauth_refresh_token', '')) !== '';
    }

    protected function oauthCredentialsComplete(): bool
    {
        return trim((string) config('backup.google_drive.oauth_client_id', '')) !== ''
            && trim((string) config('backup.google_drive.oauth_client_secret', '')) !== ''
            && trim((string) config('backup.google_drive.oauth_refresh_token', '')) !== '';
    }

    /**
     * @return array{accessible: bool, shared_drive: bool|null, issue: string}|null
     */
    protected function folderAccessCheck(?Drive $service = null): ?array
    {
        if (! config('backup.google_drive.verify_folder_access', true)) {
            return null;
        }

        $folderId = trim((string) config('backup.google_drive.folder_id', ''));
        if ($folderId === '' || $this->resolveAuthConfig() === null) {
            return null;
        }

        $email = $this->usesOAuth()
            ? null
            : $this->serviceAccountEmailFromConfig($this->resolveAuthConfig());

        try {
            $service ??= $this->driveService();
            $folder = $service->files->get($folderId, [
                'supportsAllDrives' => true,
                'fields' => 'id,name,driveId,capabilities',
            ]);
            $canAdd = $folder->getCapabilities()?->getCanAddChildren() ?? true;

            if (! $canAdd) {
                return [
                    'accessible' => false,
                    'shared_drive' => $folder->getDriveId() !== null,
                    'issue' => 'The service account can see the folder but cannot add files. Grant Editor (or Shared drive Content manager) to '
                        .($email ?? 'the backup service account').'.',
                ];
            }

            return [
                'accessible' => true,
                'shared_drive' => $folder->getDriveId() !== null,
                'issue' => '',
            ];
        } catch (\Throwable $e) {
            $message = $this->humanizeDriveError($e->getMessage(), $email);

            return [
                'accessible' => false,
                'shared_drive' => null,
                'issue' => $message,
            ];
        }
    }

    protected function humanizeDriveError(string $raw, ?string $serviceAccountEmail = null): string
    {
        $account = $serviceAccountEmail ?? 'the backup service account';

        if (str_contains($raw, 'storageQuotaExceeded') || str_contains($raw, 'do not have storage quota')) {
            return 'Google Drive rejected the upload: service accounts have no storage of their own. Share folder '
                .$account.' as Editor so files use your Drive quota, or use a Shared drive (Google Workspace).';
        }

        if (str_contains($raw, 'notFound') || str_contains($raw, 'File not found')) {
            return 'The backup folder is not visible to '.$account.'. Open the folder in Drive → Share → add '
                .$account.' as Editor, then retry.';
        }

        return trim($raw) !== '' ? $raw : 'Google Drive access check failed.';
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
