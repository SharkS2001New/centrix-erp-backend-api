<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CloudflareR2BackupUploader
{
    public function isEnabled(): bool
    {
        $cfg = BackupR2SettingsResolver::applyToRuntime();

        return (bool) $cfg['enabled'] && BackupR2SettingsResolver::isConfigured($cfg);
    }

    public function isConfigured(): bool
    {
        return BackupR2SettingsResolver::isConfigured(BackupR2SettingsResolver::resolve());
    }

    /**
     * @return array{
     *     enabled: bool,
     *     configured: bool,
     *     upload_ready: bool,
     *     bucket: string|null,
     *     endpoint: string|null,
     *     prefix: string|null,
     *     public_url: string|null,
     *     source: string|null,
     *     issues: list<string>,
     *     setup_notes: list<string>,
     * }
     */
    public function diagnostics(): array
    {
        $described = BackupR2SettingsResolver::describe();
        $effective = $described['effective'];
        $enabledFlag = (bool) $effective['enabled'];
        $configured = (bool) $effective['configured'];
        $uploadReady = (bool) $effective['upload_ready'];

        $issues = [];
        if (! $enabledFlag) {
            $issues[] = 'Enable Cloudflare R2 upload in Platform → Database backups settings.';
        }

        $cfg = BackupR2SettingsResolver::resolve();
        foreach ([
            'access_key_id' => 'Access key ID',
            'secret_access_key' => 'Secret access key',
            'bucket' => 'Bucket name',
            'endpoint' => 'Endpoint URL',
        ] as $key => $label) {
            if (trim((string) ($cfg[$key] ?? '')) === '') {
                $issues[] = "Set {$label} in Platform → Database backups settings.";
            }
        }

        $setupNotes = [];
        if ($configured) {
            $setupNotes[] = 'Backups are uploaded to Cloudflare R2 after each local dump.';
            if (trim((string) ($cfg['public_url'] ?? '')) === '') {
                $setupNotes[] = 'Optional: set a public/custom domain URL for clickable object links.';
            }
            if (($effective['source'] ?? '') === 'environment') {
                $setupNotes[] = 'Currently using BACKUP_R2_* environment fallback. Save settings here to manage credentials in the UI.';
            }
        }

        return [
            'enabled' => $uploadReady,
            'configured' => $configured,
            'upload_ready' => $uploadReady,
            'bucket' => $this->nonEmpty($effective['bucket'] ?? null),
            'endpoint' => $this->nonEmpty($effective['endpoint'] ?? null),
            'prefix' => $this->nonEmpty($effective['prefix'] ?? null) ?: 'backups/database',
            'public_url' => $this->nonEmpty($effective['public_url'] ?? null),
            'source' => $effective['source'] ?? null,
            'issues' => array_values(array_unique($issues)),
            'setup_notes' => $setupNotes,
        ];
    }

    /**
     * @return array{file_id: string, name: string, web_view_link: string|null, bucket: string}
     */
    public function upload(string $absolutePath, string $filename): array
    {
        if (! is_file($absolutePath)) {
            throw new DatabaseBackupException(
                'Local backup file was not found for R2 upload.',
                'r2_source_missing',
            );
        }

        $cfg = BackupR2SettingsResolver::applyToRuntime();

        if (! ((bool) $cfg['enabled'] && BackupR2SettingsResolver::isConfigured($cfg))) {
            throw new DatabaseBackupException(
                'Cloudflare R2 backup upload is not enabled or configured.',
                'r2_not_configured',
            );
        }

        $disk = (string) config('backup.r2.disk', 'r2');
        $prefix = trim((string) ($cfg['prefix'] ?? 'backups/database'), '/');
        $objectKey = ($prefix !== '' ? $prefix.'/' : '').ltrim($filename, '/');

        Storage::forgetDisk($disk);

        try {
            $stream = fopen($absolutePath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Could not open backup file for upload.');
            }

            try {
                $ok = Storage::disk($disk)->put($objectKey, $stream, [
                    'visibility' => 'private',
                    'ContentType' => str_ends_with($filename, '.gz')
                        ? 'application/gzip'
                        : 'application/sql',
                ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($ok === false) {
                throw new \RuntimeException('R2 put() returned false.');
            }
        } catch (DatabaseBackupException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Cloudflare R2 backup upload failed', [
                'filename' => $filename,
                'object_key' => $objectKey,
                'error' => $e->getMessage(),
            ]);

            throw new DatabaseBackupException(
                $this->humanizeErrorForUser($e->getMessage()),
                'r2_upload_failed',
                $e,
            );
        }

        return [
            'file_id' => $objectKey,
            'name' => $filename,
            'web_view_link' => $this->objectUrl($objectKey, $cfg['public_url'] ?? ''),
            'bucket' => (string) $cfg['bucket'],
        ];
    }

    /**
     * Verify credentials can write/read/delete a tiny probe object (probe is removed).
     *
     * @param  array<string, mixed>  $overrides
     * @return array{ok: bool, message: string, bucket: string, endpoint: string, prefix: string}
     */
    public function testConnection(array $overrides = []): array
    {
        $cfg = BackupR2SettingsResolver::resolveWithOverrides($overrides);
        $this->assertConfiguredForTest($cfg);
        BackupR2SettingsResolver::applyConfig($cfg);

        $disk = (string) config('backup.r2.disk', 'r2');
        $prefix = trim((string) ($cfg['prefix'] ?? 'backups/database'), '/');
        $probeKey = ($prefix !== '' ? $prefix.'/' : '').'connection-tests/_centrix_probe_'.uniqid('', true).'.txt';

        Storage::forgetDisk($disk);

        try {
            $ok = Storage::disk($disk)->put($probeKey, "centrix-r2-connection-probe\n", [
                'visibility' => 'private',
                'ContentType' => 'text/plain',
            ]);
            if ($ok === false) {
                throw new \RuntimeException('R2 put() returned false during connection test.');
            }
            if (! Storage::disk($disk)->exists($probeKey)) {
                throw new \RuntimeException('Probe object was written but could not be read back from R2.');
            }
            Storage::disk($disk)->delete($probeKey);
        } catch (DatabaseBackupException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Cloudflare R2 connection test failed', [
                'error' => $e->getMessage(),
                'bucket' => $cfg['bucket'] ?? null,
            ]);

            throw new DatabaseBackupException(
                $this->humanizeErrorForUser($e->getMessage()),
                'r2_connection_failed',
                $e,
            );
        }

        return [
            'ok' => true,
            'message' => 'Connected to Cloudflare R2 successfully.',
            'bucket' => (string) $cfg['bucket'],
            'endpoint' => (string) $cfg['endpoint'],
            'prefix' => $prefix !== '' ? $prefix : 'backups/database',
        ];
    }

    /**
     * Upload a small marker file and leave it in the bucket for manual verification.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{ok: bool, message: string, file_id: string, name: string, bucket: string, web_view_link: string|null}
     */
    public function testUpload(array $overrides = []): array
    {
        $cfg = BackupR2SettingsResolver::resolveWithOverrides($overrides);
        $this->assertConfiguredForTest($cfg);
        BackupR2SettingsResolver::applyConfig($cfg);

        $disk = (string) config('backup.r2.disk', 'r2');
        $prefix = trim((string) ($cfg['prefix'] ?? 'backups/database'), '/');
        $filename = 'centrix-r2-test-'.now()->format('Ymd-His').'.txt';
        $objectKey = ($prefix !== '' ? $prefix.'/' : '').'connection-tests/'.$filename;
        $body = "Centrix ERP Cloudflare R2 upload test\n"
            ."Uploaded at: ".now()->toIso8601String()."\n"
            ."Bucket: {$cfg['bucket']}\n"
            ."Object: {$objectKey}\n";

        Storage::forgetDisk($disk);

        try {
            $ok = Storage::disk($disk)->put($objectKey, $body, [
                'visibility' => 'private',
                'ContentType' => 'text/plain',
            ]);
            if ($ok === false) {
                throw new \RuntimeException('R2 put() returned false during upload test.');
            }
            if (! Storage::disk($disk)->exists($objectKey)) {
                throw new \RuntimeException('Test object was written but could not be read back from R2.');
            }
        } catch (DatabaseBackupException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Cloudflare R2 upload test failed', [
                'error' => $e->getMessage(),
                'object_key' => $objectKey,
            ]);

            throw new DatabaseBackupException(
                $this->humanizeErrorForUser($e->getMessage()),
                'r2_upload_test_failed',
                $e,
            );
        }

        return [
            'ok' => true,
            'message' => 'Test file uploaded to Cloudflare R2. Confirm it appears in your bucket.',
            'file_id' => $objectKey,
            'name' => $filename,
            'bucket' => (string) $cfg['bucket'],
            'web_view_link' => $this->objectUrl($objectKey, $cfg['public_url'] ?? ''),
        ];
    }

    /** @param  array<string, mixed>  $cfg */
    protected function assertConfiguredForTest(array $cfg): void
    {
        if (! BackupR2SettingsResolver::isConfigured($cfg)) {
            throw new DatabaseBackupException(
                'Cloudflare R2 is not fully configured. Set access key, secret, bucket, and endpoint first.',
                'r2_not_configured',
            );
        }
    }

    public function humanizeErrorForUser(string $raw): string
    {
        $message = trim($raw);
        if ($message === '') {
            return 'Cloudflare R2 upload failed.';
        }

        if (str_contains($message, 'InvalidAccessKeyId') || str_contains($message, 'SignatureDoesNotMatch')) {
            return 'Cloudflare R2 rejected the access key or secret. Update credentials in Platform → Database backups settings.';
        }

        if (str_contains($message, 'NoSuchBucket')) {
            return 'Cloudflare R2 bucket was not found. Check the bucket name in Platform → Database backups settings.';
        }

        if (str_contains($message, 'Could not resolve host') || str_contains($message, 'cURL error')) {
            return 'Could not reach Cloudflare R2. Check the endpoint URL and network access from the API server.';
        }

        return 'Cloudflare R2 upload failed: '.substr(preg_replace('/\s+/', ' ', $message) ?? $message, 0, 240);
    }

    protected function objectUrl(string $objectKey, string $publicUrl = ''): ?string
    {
        $public = rtrim($publicUrl !== '' ? $publicUrl : (string) config('backup.r2.public_url', ''), '/');
        if ($public === '') {
            return null;
        }

        return $public.'/'.ltrim($objectKey, '/');
    }

    protected function nonEmpty(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
