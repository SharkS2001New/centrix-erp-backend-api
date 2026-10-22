<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunDatabaseBackupJob;
use App\Services\Background\BackgroundTaskService;
use App\Services\Backup\BackupR2SettingsResolver;
use App\Services\Backup\BackupScheduleSettingsResolver;
use App\Services\Backup\CloudflareR2BackupUploader;
use App\Services\Backup\DatabaseBackupException;
use App\Services\Backup\DatabaseBackupService;
use App\Services\Backup\DatabaseRestoreException;
use App\Services\Backup\DatabaseRestoreService;
use App\Services\Backup\RestoreDatasetCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PlatformDatabaseBackupController extends Controller
{
    public function __construct(
        protected DatabaseBackupService $backups,
        protected DatabaseRestoreService $restores,
        protected RestoreDatasetCatalog $restoreDatasets,
        protected BackgroundTaskService $tasks,
    ) {}

    /** GET /api/v1/admin/database-backups */
    public function index()
    {
        try {
            $r2 = app(CloudflareR2BackupUploader::class)->diagnostics();

            return response()->json([
                'data' => $this->backups->listBackups(),
                'r2_enabled' => $r2['upload_ready'],
                'r2_configured' => $r2['configured'],
                'r2' => $r2,
                'r2_settings' => BackupR2SettingsResolver::describe(),
                'schedule' => BackupScheduleSettingsResolver::describe(),
                'restore_datasets' => $this->restoreDatasets->datasets(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return $this->backupErrorResponse($e, 'Could not list database backups.', 500);
        }
    }

    /** GET /api/v1/admin/database-backup-settings */
    public function showSettings()
    {
        return response()->json(array_merge(
            BackupR2SettingsResolver::describe(),
            ['schedule' => BackupScheduleSettingsResolver::describe()],
        ));
    }

    /** PUT /api/v1/admin/database-backup-settings */
    public function updateSettings(Request $request)
    {
        $data = $request->validate(array_merge($this->r2SettingsRules(), $this->scheduleSettingsRules()));

        $r2Keys = array_keys($this->r2SettingsRules());
        $scheduleKeys = array_keys($this->scheduleSettingsRules());
        $r2Data = array_intersect_key($data, array_flip($r2Keys));
        $scheduleData = array_intersect_key($data, array_flip($scheduleKeys));

        $response = $r2Data !== []
            ? BackupR2SettingsResolver::save($r2Data)
            : BackupR2SettingsResolver::describe();

        if ($scheduleData !== []) {
            if (array_key_exists('schedule_enabled', $scheduleData)) {
                $scheduleData['enabled'] = (bool) $scheduleData['schedule_enabled'];
                unset($scheduleData['schedule_enabled']);
            }
            $response['schedule'] = BackupScheduleSettingsResolver::save($scheduleData);
        } else {
            $response['schedule'] = BackupScheduleSettingsResolver::describe();
        }

        return response()->json($response);
    }

    /** POST /api/v1/admin/database-backup-settings/test-connection */
    public function testR2Connection(Request $request)
    {
        try {
            $overrides = $request->validate($this->r2SettingsRules());
            $result = app(CloudflareR2BackupUploader::class)->testConnection($overrides);

            return response()->json($result);
        } catch (\Throwable $e) {
            report($e);

            return $this->backupErrorResponse($e, 'Cloudflare R2 connection test failed.', 422);
        }
    }

    /** POST /api/v1/admin/database-backup-settings/test-upload */
    public function testR2Upload(Request $request)
    {
        try {
            $overrides = $request->validate($this->r2SettingsRules());
            $result = app(CloudflareR2BackupUploader::class)->testUpload($overrides);

            return response()->json($result);
        } catch (\Throwable $e) {
            report($e);

            return $this->backupErrorResponse($e, 'Cloudflare R2 upload test failed.', 422);
        }
    }

    /** @return array<string, mixed> */
    protected function r2SettingsRules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'access_key_id' => ['nullable', 'string', 'max:255'],
            'secret_access_key' => ['nullable', 'string', 'max:255'],
            'bucket' => ['nullable', 'string', 'max:255'],
            'endpoint' => ['nullable', 'string', 'max:500'],
            'region' => ['nullable', 'string', 'max:64'],
            'prefix' => ['nullable', 'string', 'max:255'],
            'public_url' => ['nullable', 'string', 'max:500'],
            'use_path_style_endpoint' => ['sometimes', 'boolean'],
            // Separate from schedule retention_days (local files).
            'r2_retention_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ];
    }

    /** @return array<string, mixed> */
    protected function scheduleSettingsRules(): array
    {
        return [
            'schedule_enabled' => ['sometimes', 'boolean'],
            'frequency' => ['sometimes', 'in:hourly,every_6_hours,every_12_hours,daily'],
            'schedule_time' => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'retention_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ];
    }

    /** POST /api/v1/admin/database-backups */
    public function store(Request $request)
    {
        try {
            try {
                BackupScheduleSettingsResolver::applyToRuntime();
            } catch (\Throwable) {
                // keep env config
            }
            if (! config('backup.enabled', true)) {
                return response()->json([
                    'message' => 'Database backups are disabled (BACKUP_ENABLED=false or schedule disabled in Platform settings).',
                    'code' => 'backup_disabled',
                ], 422);
            }

            $validated = $request->validate([
                'send_email' => ['sometimes', 'boolean'],
                'upload_r2' => ['sometimes', 'boolean'],
                'async' => ['sometimes', 'boolean'],
            ]);

            $sendEmail = (bool) ($validated['send_email'] ?? true);
            $uploadR2 = (bool) ($validated['upload_r2'] ?? true);

            if ($request->boolean('async') && Schema::hasTable('background_tasks')) {
                try {
                    $task = $this->tasks->create('database_backup', $request->user(), [
                        'send_email' => $sendEmail,
                        'upload_r2' => $uploadR2,
                    ]);

                    $this->tasks->dispatch(RunDatabaseBackupJob::class, $task);

                    return response()->json([
                        'message' => 'Database backup queued.',
                        'task_id' => $task->id,
                        'queued' => true,
                    ], 202);
                } catch (\Throwable $e) {
                    report($e);
                    // Fall back to inline backup when queue/background_tasks is unavailable.
                }
            }

            @set_time_limit(0);

            $result = $this->backups->runBackupCycle(
                sendEmail: $sendEmail,
                prune: true,
                uploadR2: $uploadR2,
            );

            return response()->json([
                'message' => $result['r2_error']
                    ? 'Database backup completed, but Cloudflare R2 upload failed.'
                    : ($result['r2'] ? 'Database backup completed and uploaded to Cloudflare R2.' : 'Database backup completed.'),
                'data' => $result['backup'],
                'r2' => $result['r2'],
                'r2_error' => $result['r2_error'],
                'r2_skipped_reason' => $result['r2_skipped_reason'],
                'email_sent' => $result['email_sent'],
                'pruned' => $result['pruned'],
                'r2_pruned' => $result['r2_pruned'] ?? 0,
            ], 201);
        } catch (\Throwable $e) {
            report($e);

            return $this->backupErrorResponse($e, 'Database backup failed.', 500);
        }
    }

    /** GET /api/v1/admin/database-backups/{filename}/download */
    public function download(Request $request, string $filename): Response
    {
        try {
            $backup = $this->backups->findBackup($filename);

            if ($backup === null) {
                abort(404, 'Backup file not found.');
            }

            Log::warning('Platform database backup downloaded', [
                'filename' => $backup['filename'],
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
            ]);

            $mimeType = $backup['compressed'] ? 'application/gzip' : 'application/sql';

            return Storage::disk($backup['disk'])->download(
                $backup['relative_path'],
                $backup['filename'],
                ['Content-Type' => $mimeType],
            );
        } catch (\Throwable $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }

            report($e);

            return $this->backupErrorResponse($e, 'Could not download database backup.', 500);
        }
    }

    /**
     * POST /api/v1/admin/database-backups/{filename}/restore
     * Restore selected tables (or full dump) from an existing backup file.
     */
    public function restoreFromBackup(Request $request, string $filename)
    {
        try {
            $validated = $request->validate([
                'mode' => ['required', 'in:merge,replace'],
                'dataset' => ['nullable', 'string', 'max:64'],
                'tables' => ['nullable', 'array'],
                'tables.*' => ['string', 'max:64'],
                'confirm_full' => ['sometimes', 'boolean'],
            ]);

            $tables = $this->resolveRestoreTables(
                $validated['dataset'] ?? null,
                $validated['tables'] ?? null,
            );

            @set_time_limit(0);

            $result = $this->restores->restoreFromStoredBackup(
                $filename,
                (string) $validated['mode'],
                $tables,
                (bool) ($validated['confirm_full'] ?? false),
            );
            $result['dataset'] = $validated['dataset'] ?? null;

            Log::warning('Platform database restore from stored backup', [
                'filename' => $filename,
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'mode' => $result['mode'],
                'dataset' => $validated['dataset'] ?? null,
                'tables' => $result['tables'],
            ]);

            return response()->json([
                'message' => $this->restoreSuccessMessage($result),
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return $this->backupErrorResponse($e, 'Database restore failed.', 422);
        }
    }

    /**
     * POST /api/v1/admin/database-backups/restore-upload
     * Upload a .sql / .sql.gz dump and restore (table-scoped or full).
     */
    public function restoreUpload(Request $request)
    {
        try {
            $validated = $request->validate([
                'file' => ['required', 'file', 'max:524288'], // KB → ~512 MB
                'mode' => ['required', 'in:merge,replace'],
                'dataset' => ['nullable', 'string', 'max:64'],
                'tables' => ['nullable'],
                'confirm_full' => ['sometimes', 'boolean'],
            ]);

            $tablesRaw = $validated['tables'] ?? null;
            $extraTables = null;
            if (is_string($tablesRaw) && trim($tablesRaw) !== '') {
                $decoded = json_decode($tablesRaw, true);
                if (is_array($decoded)) {
                    $extraTables = $decoded;
                } else {
                    $extraTables = array_values(array_filter(array_map('trim', explode(',', $tablesRaw))));
                }
            } elseif (is_array($tablesRaw)) {
                $extraTables = $tablesRaw;
            }

            $tables = $this->resolveRestoreTables(
                $validated['dataset'] ?? null,
                $extraTables,
            );

            $upload = $request->file('file');
            $original = (string) $upload->getClientOriginalName();
            $ext = strtolower((string) $upload->getClientOriginalExtension());
            if (! in_array($ext, ['sql', 'gz'], true)
                && ! str_ends_with(strtolower($original), '.sql.gz')
                && ! str_ends_with(strtolower($original), '.sql')) {
                return response()->json([
                    'message' => 'Upload a .sql or .sql.gz dump.',
                    'code' => 'invalid_file_type',
                ], 422);
            }

            $tempPath = $upload->getRealPath();
            if (! is_string($tempPath) || $tempPath === '' || ! is_file($tempPath)) {
                return response()->json([
                    'message' => 'Uploaded file could not be read.',
                    'code' => 'file_unreadable',
                ], 422);
            }

            // Persist upload to a stable temp path (PHP may clean the upload tmp after the request).
            $stable = tempnam(sys_get_temp_dir(), 'erp_restore_up_');
            if ($stable === false) {
                return response()->json([
                    'message' => 'Could not stage uploaded dump.',
                    'code' => 'temp_failed',
                ], 500);
            }
            $suffix = str_ends_with(strtolower($original), '.sql.gz') || $ext === 'gz' ? '.sql.gz' : '.sql';
            $staged = $stable.$suffix;
            @unlink($stable);
            if (! @copy($tempPath, $staged)) {
                return response()->json([
                    'message' => 'Could not stage uploaded dump.',
                    'code' => 'temp_failed',
                ], 500);
            }

            @set_time_limit(0);

            try {
                $result = $this->restores->restoreFromAbsolutePath(
                    $staged,
                    (string) $validated['mode'],
                    $tables,
                    str_ends_with(strtolower($staged), '.gz'),
                    (bool) ($validated['confirm_full'] ?? false),
                    $original,
                );
            } finally {
                @unlink($staged);
            }

            Log::warning('Platform database restore from upload', [
                'filename' => $original,
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'mode' => $result['mode'],
                'tables' => $result['tables'],
            ]);

            $result['dataset'] = $validated['dataset'] ?? null;

            return response()->json([
                'message' => $this->restoreSuccessMessage($result),
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return $this->backupErrorResponse($e, 'Database restore failed.', 422);
        }
    }

    /**
     * Explicit table list wins. Otherwise expand a named dataset pack.
     *
     * @param  list<string>|null  $tables
     * @return list<string>|null
     */
    protected function resolveRestoreTables(?string $dataset, ?array $tables): ?array
    {
        if (is_array($tables) && $tables !== []) {
            return $this->restores->normalizeTables($tables);
        }

        if ($dataset !== null && trim($dataset) !== '') {
            return $this->restoreDatasets->resolveTables($dataset);
        }

        return null;
    }

    /** @param  array{mode: string, tables: list<string>|null, statements_executed: int, truncated: list<string>}  $result */
    protected function restoreSuccessMessage(array $result): string
    {
        $scope = $result['tables'] === null || $result['tables'] === []
            ? 'full dump'
            : ('table(s) '.implode(', ', $result['tables']));
        $mode = $result['mode'] === 'replace' ? 'replace (truncate then load)' : 'merge (insert ignore)';

        return sprintf(
            'Restore completed (%s, %s): %d statement(s) executed.',
            $scope,
            $mode,
            (int) $result['statements_executed'],
        );
    }

    protected function backupErrorResponse(\Throwable $e, string $message, int $status)
    {
        $code = 'backup_failed';
        $detail = null;

        if ($e instanceof DatabaseBackupException || $e instanceof DatabaseRestoreException) {
            $code = $e->codeKey;
            $detail = $e->getMessage();
            if ($e instanceof DatabaseRestoreException && $e->codeKey === 'confirm_full_required') {
                $status = 422;
            }
        } elseif (config('app.debug')) {
            $detail = $e->getMessage();
        } elseif (config('backup.expose_error_detail', true)) {
            $detail = $e->getMessage();
        }

        return response()->json(array_filter([
            'message' => $message,
            'code' => $code,
            'detail' => $detail,
        ], fn ($value) => $value !== null && $value !== ''), $status);
    }
}
