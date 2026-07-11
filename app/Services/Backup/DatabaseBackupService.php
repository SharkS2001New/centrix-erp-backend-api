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
            throw new DatabaseBackupException(
                "Unknown database connection [{$connection}].",
                'unknown_connection',
            );
        }

        $driver = (string) ($config['driver'] ?? '');
        $database = (string) ($config['database'] ?? '');
        $databaseLabel = basename($database) ?: 'database';
        $disk = (string) config('backup.disk', 'local');
        $directory = trim((string) config('backup.path', 'backups/database'), '/');
        $timestamp = now()->format('Y-m-d_His');
        $filename = sprintf('%s_%s.sql', $databaseLabel, $timestamp);
        $relativePath = $directory.'/'.$filename;

        $this->assertBackupStorageReady($disk, $directory);

        $absolutePath = Storage::disk($disk)->path($relativePath);

        try {
            match ($driver) {
                'mysql', 'mariadb' => $this->dumpMysql($config, $absolutePath),
                'sqlite' => $this->dumpSqlite($config, $absolutePath),
                default => throw new DatabaseBackupException(
                    "Database driver [{$driver}] is not supported for backups.",
                    'unsupported_driver',
                ),
            };
        } catch (DatabaseBackupException $e) {
            throw $e;
        } catch (ProcessFailedException $e) {
            throw new DatabaseBackupException(
                $this->describeProcessFailure($e),
                'mysqldump_failed',
                $e,
            );
        } catch (\Throwable $e) {
            throw new DatabaseBackupException(
                'Database backup failed while exporting data.',
                'export_failed',
                $e,
            );
        }

        $compressed = false;
        if (config('backup.compress', true)) {
            try {
                $gzipPath = $absolutePath.'.gz';
                $this->gzipFile($absolutePath, $gzipPath);
                @unlink($absolutePath);
                $relativePath .= '.gz';
                $absolutePath = $gzipPath;
                $filename .= '.gz';
                $compressed = true;
            } catch (\Throwable $e) {
                @unlink($absolutePath);
                throw new DatabaseBackupException(
                    'Database backup could not be compressed.',
                    'compress_failed',
                    $e,
                );
            }
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

        try {
            if (! Storage::disk($disk)->exists($directory)) {
                return [];
            }

            $paths = Storage::disk($disk)->files($directory);
        } catch (\Throwable $e) {
            Log::warning('Could not list database backup directory', [
                'disk' => $disk,
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $files = [];

        foreach ($paths as $path) {
            if (! $this->isBackupFilename(basename($path))) {
                continue;
            }

            try {
                $files[] = [
                    'filename' => basename($path),
                    'relative_path' => $path,
                    'size_bytes' => (int) Storage::disk($disk)->size($path),
                    'compressed' => str_ends_with($path, '.gz'),
                    'created_at' => \Illuminate\Support\Carbon::createFromTimestamp(
                        (int) Storage::disk($disk)->lastModified($path)
                    )->toIso8601String(),
                ];
            } catch (\Throwable $e) {
                Log::warning('Skipping unreadable database backup file', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
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
            'created_at' => \Illuminate\Support\Carbon::createFromTimestamp(
                (int) Storage::disk($disk)->lastModified($relativePath)
            )->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     backup: array<string, mixed>,
     *     google_drive: array<string, mixed>|null,
     *     google_drive_error: string|null,
     *     google_drive_skipped_reason: string|null,
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
        $googleDriveError = null;
        $googleDriveSkippedReason = null;
        $uploader = app(GoogleDriveBackupUploader::class);

        if ($uploadGoogleDrive) {
            if ($uploader->isEnabled()) {
                try {
                    $googleDrive = $uploader->upload(
                        $backup['absolute_path'],
                        $backup['filename'],
                    );
                } catch (\Throwable $e) {
                    $googleDriveError = app(GoogleDriveBackupUploader::class)
                        ->humanizeDriveErrorForUser($e->getMessage());
                    Log::warning('Google Drive backup upload failed', [
                        'filename' => $backup['filename'],
                        'error' => $googleDriveError,
                    ]);
                }
            } else {
                $diagnostics = $uploader->diagnostics();
                $googleDriveSkippedReason = $diagnostics['issues'][0]
                    ?? 'Google Drive upload is not configured on the API server.';
            }
        }

        $pruned = $prune ? $this->pruneOldBackups() : 0;
        $emailSent = $sendEmail ? $this->notifyByEmail($backup) : false;

        return [
            'backup' => $backup,
            'google_drive' => $googleDrive,
            'google_drive_error' => $googleDriveError,
            'google_drive_skipped_reason' => $googleDriveSkippedReason,
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
        $retentionDays = $retentionDays ?? (int) config('backup.retention_days', 7);
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
        if ($binary !== 'mysqldump' && ! is_executable($binary)) {
            throw new DatabaseBackupException(
                "Backup binary [{$binary}] was not found or is not executable.",
                'mysqldump_missing',
            );
        }

        $process = new Process([
            $binary,
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? '3306'),
            '--user='.($config['username'] ?? 'root'),
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            // Named columns help modern mysqldump omit generated values; we still sanitize CREATE + footer.
            '--complete-insert',
            '--result-file='.$absolutePath,
            (string) ($config['database'] ?? ''),
        ], null, $this->mysqlEnvironment($config));

        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($absolutePath);

            throw new ProcessFailedException($process);
        }

        app(MysqlDumpGeneratedColumnSanitizer::class)->sanitizeFile(
            $absolutePath,
            $this->generatedColumnsForDump($config),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, list<array{name: string, type: string, expression: string, stored: bool}>>
     */
    protected function generatedColumnsForDump(array $config): array
    {
        try {
            $pdo = new \PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s',
                    $config['host'] ?? '127.0.0.1',
                    $config['port'] ?? '3306',
                    $config['database'] ?? '',
                ),
                (string) ($config['username'] ?? 'root'),
                (string) ($config['password'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );

            $stmt = $pdo->query(
                "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, GENERATION_EXPRESSION, EXTRA
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND EXTRA LIKE '%GENERATED%'
                 ORDER BY TABLE_NAME, ORDINAL_POSITION"
            );

            $byTable = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $table = (string) $row['TABLE_NAME'];
                $extra = strtoupper((string) $row['EXTRA']);
                $expression = trim((string) $row['GENERATION_EXPRESSION']);
                if ($expression !== '' && $expression[0] !== '(') {
                    $expression = '('.$expression.')';
                }
                $byTable[$table][] = [
                    'name' => (string) $row['COLUMN_NAME'],
                    'type' => strtoupper((string) $row['COLUMN_TYPE']),
                    'expression' => $expression !== '' ? $expression : '(NULL)',
                    'stored' => str_contains($extra, 'STORED'),
                ];
            }

            if ($byTable !== []) {
                return $byTable;
            }
        } catch (\Throwable $e) {
            Log::warning('Could not introspect generated columns for dump sanitization; using known defaults.', [
                'error' => $e->getMessage(),
            ]);
        }

        return MysqlDumpGeneratedColumnSanitizer::KNOWN_COLUMNS;
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

    protected function assertBackupStorageReady(string $disk, string $directory): void
    {
        try {
            Storage::disk($disk)->makeDirectory($directory);
        } catch (\Throwable $e) {
            throw new DatabaseBackupException(
                'Backup storage directory could not be created.',
                'storage_not_writable',
                $e,
            );
        }

        $root = Storage::disk($disk)->path($directory);
        if (! is_dir($root)) {
            throw new DatabaseBackupException(
                'Backup storage directory does not exist.',
                'storage_missing',
            );
        }

        if (! is_writable($root)) {
            throw new DatabaseBackupException(
                'Backup storage directory is not writable by the API process.',
                'storage_not_writable',
            );
        }
    }

    protected function describeProcessFailure(ProcessFailedException $e): string
    {
        $output = trim($e->getProcess()->getErrorOutput()."\n".$e->getProcess()->getOutput());

        if (str_contains($output, 'command not found') || str_contains($output, 'No such file or directory')) {
            return 'mysqldump is not installed on the API server.';
        }

        if (str_contains($output, 'Access denied')) {
            return 'Database rejected the backup connection. Check DB credentials and allow remote access from the API pod.';
        }

        if (str_contains($output, "Can't connect")) {
            return 'Could not reach the database server from the API pod.';
        }

        if ($output !== '') {
            return 'mysqldump failed: '.substr(preg_replace('/\s+/', ' ', $output), 0, 240);
        }

        return 'mysqldump failed.';
    }
}
