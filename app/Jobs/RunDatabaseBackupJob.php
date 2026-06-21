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
                uploadGoogleDrive: (bool) ($payload['upload_google_drive'] ?? true),
            );

            $tasks->markCompleted($task, [
                'message' => 'Database backup completed.',
                'backup' => $result['backup'] ?? null,
                'google_drive' => $result['google_drive'] ?? null,
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
