<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformDatabaseBackupController extends Controller
{
    public function __construct(
        protected DatabaseBackupService $backups,
    ) {}

    /** GET /api/v1/admin/database-backups */
    public function index()
    {
        return response()->json([
            'data' => $this->backups->listBackups(),
            'google_drive_enabled' => app(\App\Services\Backup\GoogleDriveBackupUploader::class)->isEnabled(),
        ]);
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
        ]);

        try {
            $result = $this->backups->runBackupCycle(
                sendEmail: (bool) ($validated['send_email'] ?? true),
                prune: true,
                uploadGoogleDrive: (bool) ($validated['upload_google_drive'] ?? true),
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Database backup failed.',
                'error' => $e->getMessage(),
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
    public function download(string $filename): StreamedResponse
    {
        $backup = $this->backups->findBackup($filename);

        if ($backup === null) {
            abort(404, 'Backup file not found.');
        }

        $mimeType = $backup['compressed'] ? 'application/gzip' : 'application/sql';

        return response()->download(
            $backup['absolute_path'],
            $backup['filename'],
            ['Content-Type' => $mimeType],
        );
    }
}
