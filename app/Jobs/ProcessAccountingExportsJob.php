<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Services\Accounting\JournalExportService;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAccountingExportsJob implements ShouldQueue
{
    use Queueable;
    use RunsBackgroundTaskOnce;

    public int $timeout = 3600;

    public function __construct(
        public string $taskId,
    ) {
        $this->onQueue((string) config('queue.names.exports', 'exports'));
    }

    public function handle(
        BackgroundTaskService $tasks,
        JournalExportService $exports,
    ): void {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($this->shouldSkipBackgroundTask($task) || ! $tasks->markRunning($task)) {
            return;
        }

        try {
            $organizationId = (int) $task->organization_id;
            $provider = $task->payload['provider'] ?? null;
            $provider = is_string($provider) && $provider !== '' ? $provider : null;

            $result = ! empty($task->payload['retry_failed'])
                ? $exports->retryFailed($organizationId, $provider)
                : $exports->processPending($organizationId, $provider);
            $tasks->markCompleted($task, $result);
        } catch (\Throwable $e) {
            $this->failBackgroundTask($tasks, $task, $e, 'ProcessAccountingExportsJob');
        }
    }
}
