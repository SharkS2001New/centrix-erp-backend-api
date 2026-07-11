<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunDatabaseBackupJob;
use App\Services\Background\BackgroundTaskService;
use App\Services\Backup\BackupR2SettingsResolver;
use App\Services\Backup\CloudflareR2BackupUploader;
use App\Services\Backup\DatabaseBackupException;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PlatformDatabaseBackupController extends Controller
{
    public function __construct(
        protected DatabaseBackupService $backups,
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
            ]);
        } catch (\Throwable $e) {
            report($e);

            return $this->backupErrorResponse($e, 'Could not list database backups.', 500);
        }
    }

    /** GET /api/v1/admin/database-backup-settings */
    public function showSettings()
    {
        return response()->json(BackupR2SettingsResolver::describe());
    }

    /** PUT /api/v1/admin/database-backup-settings */
    public function updateSettings(Request $request)
    {
        $data = $request->validate($this->r2SettingsRules());

        return response()->json(BackupR2SettingsResolver::save($data));
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
        ];
    }

    /** POST /api/v1/admin/database-backups */
    public function store(Request $request)
    {
        try {
            if (! config('backup.enabled', true)) {
                return response()->json([
                    'message' => 'Database backups are disabled (BACKUP_ENABLED=false).',
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

                    RunDatabaseBackupJob::dispatch($task->id);

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

    protected function backupErrorResponse(\Throwable $e, string $message, int $status)
    {
        $code = 'backup_failed';
        $detail = null;

        if ($e instanceof DatabaseBackupException) {
            $code = $e->codeKey;
            $detail = $e->getMessage();
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
