<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunDatabaseBackupJob;
use App\Services\Background\BackgroundTaskService;
use App\Services\Backup\DatabaseBackupService;
use App\Services\Backup\GoogleDriveBackupUploader;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            $drive = GoogleDriveBackupUploader::status();

            return response()->json([
                'data' => $this->backups->listBackups(),
                'google_drive_enabled' => $drive['enabled'],
                'google_drive_configured' => $drive['configured'],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not list database backups.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /** POST /api/v1/admin/database-backups */
    public function store(Request $request)
    {
        if (! config('backup.enabled', true)) {
            return response()->json([
                'message' => 'Database backups are disabled (BACKUP_ENABLED=false).',
            ], 422);
        }

        $validated = $request->validate([
            'send_email' => ['sometimes', 'boolean'],
            'upload_google_drive' => ['sometimes', 'boolean'],
            'async' => ['sometimes', 'boolean'],
        ]);

        $sendEmail = (bool) ($validated['send_email'] ?? true);
        $uploadGoogleDrive = (bool) ($validated['upload_google_drive'] ?? true);

        if ($request->boolean('async')) {
            try {
                $task = $this->tasks->create('database_backup', $request->user(), [
                    'send_email' => $sendEmail,
                    'upload_google_drive' => $uploadGoogleDrive,
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

        try {
            @set_time_limit(0);

            $result = $this->backups->runBackupCycle(
                sendEmail: $sendEmail,
                prune: true,
                uploadGoogleDrive: $uploadGoogleDrive,
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Database backup failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        return response()->json([
            'message' => 'Database backup completed.',
            'data' => $result['backup'],
            'google_drive' => $result['google_drive'],
            'email_sent' => $result['email_sent'],
            'pruned' => $result['pruned'],
        ], 201);
    }

    /** GET /api/v1/admin/database-backups/{filename}/download */
    public function download(Request $request, string $filename): StreamedResponse
    {
        $backup = $this->backups->findBackup($filename);

        if ($backup === null) {
            abort(404, 'Backup file not found.');
        }

        \Illuminate\Support\Facades\Log::warning('Platform database backup downloaded', [
            'filename' => $backup['filename'],
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ]);

        $mimeType = $backup['compressed'] ? 'application/gzip' : 'application/sql';

        return response()->download(
            $backup['absolute_path'],
            $backup['filename'],
            ['Content-Type' => $mimeType],
        );
    }
}
