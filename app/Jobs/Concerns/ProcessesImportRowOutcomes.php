<?php

namespace App\Jobs\Concerns;

use App\Models\BackgroundTask;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Database\UniqueConstraintViolationException;

trait ProcessesImportRowOutcomes
{
    /**
     * @param  array<int, array<string, mixed>>  $failures
     * @return array{created: int, skipped: int, failed: int, failures: array<int, array<string, mixed>>}
     */
    protected function buildImportResult(int $created, int $skipped, array $failures): array
    {
        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => count($failures),
            'failures' => array_slice($failures, 0, 50),
        ];
    }

    protected function shouldSkipDuplicateImport(\Throwable $e): bool
    {
        if ($e instanceof UniqueConstraintViolationException) {
            return true;
        }

        return $this->isDuplicateImportMessage($e->getMessage());
    }

    protected function isDuplicateImportMessage(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'duplicate')
            || str_contains($normalized, 'already exists')
            || str_contains($normalized, 'already registered')
            || str_contains($normalized, 'already in use')
            || str_contains($normalized, 'unique constraint');
    }

    protected function reportImportLoopProgress(
        BackgroundTaskService $tasks,
        BackgroundTask $task,
        int $index,
        int $total,
    ): void {
        if ($total <= 0) {
            return;
        }

        $step = max(1, (int) floor($total / 20));
        if (($index + 1) % $step !== 0 && ($index + 1) !== $total) {
            return;
        }

        $this->reportProgress($tasks, $task, (int) floor((($index + 1) / $total) * 100));
    }
}
