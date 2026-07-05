<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Accounting\JournalExportService;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessAccountingExportsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(
        BackgroundTaskService $tasks,
        JournalExportService $exports,
    ): void {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($task === null) {
            return;
        }

        $tasks->markRunning($task);

        try {
            $organizationId = (int) $task->organization_id;
            $provider = $task->payload['provider'] ?? null;
            $provider = is_string($provider) && $provider !== '' ? $provider : null;

            $result = ! empty($task->payload['retry_failed'])
                ? $exports->retryFailed($organizationId, $provider)
                : $exports->processPending($organizationId, $provider);
            $tasks->markCompleted($task, $result);
        } catch (\Throwable $e) {
            Log::warning('ProcessAccountingExportsJob failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
            $tasks->markFailed($task, $e->getMessage());
            throw $e;
        }
    }
}
