<?php

namespace App\Jobs\Concerns;

use App\Models\BackgroundTask;
use App\Models\User;
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

        $message = $this->formatBackgroundTaskErrorMessage($e);

        Log::warning($logContext.' failed', [
            'task_id' => $this->taskId,
            'error' => $message,
            'exception' => $e::class,
        ]);

        $tasks->markFailed($task, $message);
    }

    public function failed(?\Throwable $e = null): void
    {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($task === null || in_array($task->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $message = $e !== null
            ? $this->formatBackgroundTaskErrorMessage($e)
            : 'Background task failed before it could finish.';

        app(BackgroundTaskService::class)->markFailed($task, $message);
    }

    protected function formatBackgroundTaskErrorMessage(\Throwable $e): string
    {
        $parts = [];
        $current = $e;
        $depth = 0;

        while ($current !== null && $depth < 4) {
            $message = trim($current->getMessage());
            if ($message !== '' && ! in_array($message, $parts, true)) {
                $parts[] = $message;
            }
            $current = $current->getPrevious();
            $depth++;
        }

        if ($parts === []) {
            return class_basename($e).': background task failed.';
        }

        return implode(' — ', $parts);
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

    protected function importOrganizationId(BackgroundTask $task, User $user): int
    {
        $organizationId = (int) ($task->organization_id ?? 0);
        if ($organizationId > 0) {
            return $organizationId;
        }

        return (int) $user->organization_id;
    }
}
