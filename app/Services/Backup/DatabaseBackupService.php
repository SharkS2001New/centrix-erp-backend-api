<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    /**
     * @return array{
     *     disk: string,
     *     relative_path: string,
     *     absolute_path: string,
     *     filename: string,
     *     size_bytes: int,
     *     compressed: bool,
     *     driver: string,
     *     database: string,
     * }
     */
    public function createBackup(?string $connection = null): array
    {
        $connection = $connection ?: (string) config('database.default');
        $config = config("database.connections.{$connection}");

        if (! is_array($config)) {
            throw new \InvalidArgumentException("Unknown database connection [{$connection}].");
        }

        $driver = (string) ($config['driver'] ?? '');
        $database = (string) ($config['database'] ?? '');
        $databaseLabel = basename($database) ?: 'database';
        $disk = (string) config('backup.disk', 'local');
        $directory = trim((string) config('backup.path', 'backups/database'), '/');
        $timestamp = now()->format('Y-m-d_His');
        $filename = sprintf('%s_%s.sql', $databaseLabel, $timestamp);
        $relativePath = $directory.'/'.$filename;

        Storage::disk($disk)->makeDirectory($directory);
        $absolutePath = Storage::disk($disk)->path($relativePath);

        match ($driver) {
            'mysql', 'mariadb' => $this->dumpMysql($config, $absolutePath),
            'sqlite' => $this->dumpSqlite($config, $absolutePath),
            default => throw new \RuntimeException("Database driver [{$driver}] is not supported for backups."),
        };

        $compressed = false;
        if (config('backup.compress', true)) {
            $gzipPath = $absolutePath.'.gz';
            $this->gzipFile($absolutePath, $gzipPath);
            @unlink($absolutePath);
            $relativePath .= '.gz';
            $absolutePath = $gzipPath;
            $filename .= '.gz';
            $compressed = true;
        }

        $sizeBytes = (int) filesize($absolutePath);

        return [
            'disk' => $disk,
            'relative_path' => $relativePath,
            'absolute_path' => $absolutePath,
            'filename' => $filename,
            'size_bytes' => $sizeBytes,
            'compressed' => $compressed,
            'driver' => $driver,
            'database' => $database,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<array{
     *     filename: string,
     *     relative_path: string,
     *     size_bytes: int,
     *     compressed: bool,
     *     created_at: string,
     * }>
     */
    public function listBackups(?string $disk = null): array
    {
        $disk = $disk ?: (string) config('backup.disk', 'local');
        $directory = trim((string) config('backup.path', 'backups/database'), '/');
        $files = [];

        foreach (Storage::disk($disk)->files($directory) as $path) {
            if (! $this->isBackupFilename(basename($path))) {
                continue;
            }

            $files[] = [
                'filename' => basename($path),
                'relative_path' => $path,
                'size_bytes' => (int) Storage::disk($disk)->size($path),
                'compressed' => str_ends_with($path, '.gz'),
                'created_at' => now()->createFromTimestamp(
                    Storage::disk($disk)->lastModified($path)
                )->toIso8601String(),
            ];
        }

        usort($files, fn (array $a, array $b) => strcmp($b['created_at'], $a['created_at']));

        return $files;
    }

    /**
     * @return array{
     *     disk: string,
     *     relative_path: string,
     *     absolute_path: string,
     *     filename: string,
     *     size_bytes: int,
     *     compressed: bool,
     *     created_at: string,
     * }|null
     */
    public function findBackup(string $filename, ?string $disk = null): ?array
    {
        if (! $this->isBackupFilename($filename)) {
            return null;
        }

        $disk = $disk ?: (string) config('backup.disk', 'local');
        $directory = trim((string) config('backup.path', 'backups/database'), '/');
        $relativePath = $directory.'/'.$filename;

        if (! Storage::disk($disk)->exists($relativePath)) {
            return null;
        }

        return [
            'disk' => $disk,
            'relative_path' => $relativePath,
            'absolute_path' => Storage::disk($disk)->path($relativePath),
            'filename' => $filename,
            'size_bytes' => (int) Storage::disk($disk)->size($relativePath),
            'compressed' => str_ends_with($filename, '.gz'),
            'created_at' => now()->createFromTimestamp(
                Storage::disk($disk)->lastModified($relativePath)
            )->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     backup: array<string, mixed>,
     *     google_drive: array<string, mixed>|null,
     *     email_sent: bool,
     *     pruned: int,
     * }
     */
    public function runBackupCycle(
        bool $sendEmail = true,
        bool $prune = true,
        bool $uploadGoogleDrive = true,
    ): array {
        $backup = $this->createBackup();
        $googleDrive = null;

        if ($uploadGoogleDrive && app(GoogleDriveBackupUploader::class)->isEnabled()) {
            try {
                $googleDrive = app(GoogleDriveBackupUploader::class)->upload(
                    $backup['absolute_path'],
                    $backup['filename'],
                );
            } catch (\Throwable $e) {
                Log::warning('Google Drive backup upload failed', [
                    'filename' => $backup['filename'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $pruned = $prune ? $this->pruneOldBackups() : 0;
        $emailSent = $sendEmail ? $this->notifyByEmail($backup) : false;

        return [
            'backup' => $backup,
            'google_drive' => $googleDrive,
            'email_sent' => $emailSent,
            'pruned' => $pruned,
        ];
    }

    protected function isBackupFilename(string $filename): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._-]+\.sql(\.gz)?$/', $filename);
    }

    public function pruneOldBackups(?string $disk = null, ?int $retentionDays = null): int
    {
        $disk = $disk ?: (string) config('backup.disk', 'local');
        $retentionDays = $retentionDays ?? (int) config('backup.retention_days', 14);
        $directory = trim((string) config('backup.path', 'backups/database'), '/');
        $cutoff = now()->subDays(max($retentionDays, 1))->getTimestamp();
        $deleted = 0;

        foreach (Storage::disk($disk)->files($directory) as $path) {
            if (! preg_match('/\.sql(\.gz)?$/', $path)) {
                continue;
            }

            if (Storage::disk($disk)->lastModified($path) >= $cutoff) {
                continue;
            }

            Storage::disk($disk)->delete($path);
            $deleted++;
        }

        return $deleted;
    }

    public function notifyByEmail(array $backup, ?string $recipient = null): bool
    {
        $recipient = trim((string) ($recipient ?: config('backup.notify_email', '')));

        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $attachMaxBytes = (int) config('backup.attach_max_bytes', 0);
        $attachPath = null;

        if ($attachMaxBytes > 0 && $backup['size_bytes'] <= $attachMaxBytes) {
            $attachPath = $backup['absolute_path'];
        }

        $body = implode("\n", array_filter([
            'Centrix ERP database backup completed.',
            '',
            'Database: '.$backup['database'],
            'Driver: '.$backup['driver'],
            'File: '.$backup['filename'],
            'Size: '.$this->formatBytes($backup['size_bytes']),
            'Stored on disk: '.$backup['disk'].'/'.$backup['relative_path'],
            'Completed at: '.now()->toDateTimeString(),
            $attachPath ? null : 'The backup file was not attached. Download it from the platform admin backup screen or server storage.',
        ]));

        try {
            Mail::raw($body, function ($message) use ($recipient, $backup, $attachPath) {
                $message->to($recipient)
                    ->subject('Centrix database backup: '.$backup['filename']);

                if ($attachPath !== null && is_file($attachPath)) {
                    $message->attach($attachPath, [
                        'as' => $backup['filename'],
                        'mime' => str_ends_with($backup['filename'], '.gz')
                            ? 'application/gzip'
                            : 'application/sql',
                    ]);
                }
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('Database backup email could not be sent', [
                'recipient' => $recipient,
                'filename' => $backup['filename'],
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function dumpMysql(array $config, string $absolutePath): void
    {
        $binary = (string) config('backup.mysqldump_binary', 'mysqldump');

        $process = new Process([
            $binary,
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? '3306'),
            '--user='.($config['username'] ?? 'root'),
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--result-file='.$absolutePath,
            (string) ($config['database'] ?? ''),
        ], null, $this->mysqlEnvironment($config));

        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($absolutePath);

            throw new ProcessFailedException($process);
        }
    }

    protected function dumpSqlite(array $config, string $absolutePath): void
    {
        $databasePath = (string) ($config['database'] ?? '');

        if ($databasePath === '' || ! is_file($databasePath)) {
            throw new \RuntimeException('SQLite database file was not found.');
        }

        if (! copy($databasePath, $absolutePath)) {
            throw new \RuntimeException('Could not copy the SQLite database file.');
        }
    }

    /**
     * @return array<string, string>
     */
    protected function mysqlEnvironment(array $config): array
    {
        $env = [];

        if (($config['password'] ?? '') !== '') {
            $env['MYSQL_PWD'] = (string) $config['password'];
        }

        return $env;
    }

    protected function gzipFile(string $sourcePath, string $destinationPath): void
    {
        $input = fopen($sourcePath, 'rb');
        $output = gzopen($destinationPath, 'wb9');

        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }

            if (is_resource($output)) {
                gzclose($output);
            }

            throw new \RuntimeException('Could not compress the database backup.');
        }

        while (! feof($input)) {
            $chunk = fread($input, 1024 * 512);
            if ($chunk === false) {
                break;
            }

            gzwrite($output, $chunk);
        }

        fclose($input);
        gzclose($output);
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
