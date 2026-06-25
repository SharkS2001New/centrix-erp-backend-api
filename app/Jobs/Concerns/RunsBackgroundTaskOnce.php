<?php

namespace App\Jobs\Concerns;

use App\Models\BackgroundTask;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Support\Facades\Log;

trait RunsBackgroundTaskOnce
{
    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return $this->taskId;
    }

    protected function shouldSkipBackgroundTask(?BackgroundTask $task): bool
    {
        if ($task === null) {
            return true;
        }

        $status = $task->fresh()?->status ?? $task->status;

        return in_array($status, ['completed', 'failed', 'cancelled'], true);
    }

    protected function failBackgroundTask(
        BackgroundTaskService $tasks,
        BackgroundTask $task,
        \Throwable $e,
        string $logContext,
    ): void {
        if ($task->fresh()?->status === 'cancelled') {
            return;
        }

        Log::warning($logContext.' failed', [
            'task_id' => $this->taskId,
            'error' => $e->getMessage(),
        ]);

        $tasks->markFailed($task, $e->getMessage());
    }

    public function failed(?\Throwable $e = null): void
    {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($task === null || in_array($task->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $message = $e?->getMessage() ?: 'Background task failed before it could finish.';
        app(BackgroundTaskService::class)->markFailed($task, $message);
    }

    protected function reportProgress(
        BackgroundTaskService $tasks,
        BackgroundTask $task,
        int $progress,
        ?string $message = null,
    ): void {
        $tasks->assertNotCancelled($task);
        $tasks->updateProgress($task, $progress, $message);
    }
}
