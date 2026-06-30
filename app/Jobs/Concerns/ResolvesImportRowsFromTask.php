<?php

namespace App\Jobs\Concerns;

use App\Models\BackgroundTask;
use App\Services\Background\BackgroundTaskService;
use App\Services\Background\ImportPayloadStorage;

trait ResolvesImportRowsFromTask
{
    /** @return array<int, array<string, mixed>> */
    protected function importRowsFromTask(BackgroundTask $task): array
    {
        $payload = is_array($task->payload) ? $task->payload : [];
        $rows = $payload['rows'] ?? [];
        if (is_array($rows) && $rows !== []) {
            return $rows;
        }

        $storage = app(ImportPayloadStorage::class);
        $path = $payload['rows_path'] ?? null;

        return $storage->loadRows(is_string($path) ? $path : null);
    }

    protected function completeImportTask(BackgroundTaskService $tasks, BackgroundTask $task, array $result): void
    {
        $tasks->assertNotCancelled($task);
        $tasks->markCompleted($task, $result);
        $this->cleanupImportRowsFile($task);
    }

    protected function failImportTask(
        BackgroundTaskService $tasks,
        BackgroundTask $task,
        \Throwable $e,
        string $logContext,
    ): void {
        $this->cleanupImportRowsFile($task);
        $this->failBackgroundTask($tasks, $task, $e, $logContext);
    }

    protected function cleanupImportRowsFile(BackgroundTask $task): void
    {
        $path = $task->payload['rows_path'] ?? null;
        if (! is_string($path) || $path === '') {
            return;
        }

        app(ImportPayloadStorage::class)->delete($path);
    }
}
