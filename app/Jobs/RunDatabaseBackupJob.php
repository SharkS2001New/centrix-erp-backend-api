<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Services\Background\BackgroundTaskService;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunDatabaseBackupJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(
        BackgroundTaskService $tasks,
        DatabaseBackupService $backups,
    ): void {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($task === null) {
            return;
        }

        $tasks->markRunning($task);

        try {
            $payload = $task->payload ?? [];
            $result = $backups->runBackupCycle(
                sendEmail: (bool) ($payload['send_email'] ?? true),
                prune: true,
                uploadR2: (bool) ($payload['upload_r2'] ?? $payload['upload_google_drive'] ?? true),
            );

            $tasks->markCompleted($task, [
                'message' => $result['r2_error']
                    ? 'Database backup completed, but Cloudflare R2 upload failed.'
                    : ($result['r2'] ? 'Database backup completed and uploaded to Cloudflare R2.' : 'Database backup completed.'),
                'backup' => $result['backup'] ?? null,
                'r2' => $result['r2'] ?? null,
                'r2_error' => $result['r2_error'] ?? null,
                'r2_skipped_reason' => $result['r2_skipped_reason'] ?? null,
                'email_sent' => $result['email_sent'] ?? false,
                'pruned' => $result['pruned'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            Log::warning('RunDatabaseBackupJob failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
            $tasks->markFailed($task, $e->getMessage());
            throw $e;
        }
    }
}
