<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Background\InternalApiPaginator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ReportRunJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(BackgroundTaskService $tasks, InternalApiPaginator $paginator): void
    {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($task === null) {
            return;
        }

        $tasks->markRunning($task);
        $tasks->updateProgress($task, 5, 'Started fetching…');

        try {
            $user = User::query()->find($task->user_id);
            if ($user === null) {
                throw new InvalidArgumentException('User not found for report run task.');
            }

            $path = (string) ($task->payload['path'] ?? '');
            $searchParams = $task->payload['search_params'] ?? [];
            if ($path === '') {
                throw new InvalidArgumentException('API path is required for report run.');
            }
            if (! is_array($searchParams)) {
                $searchParams = [];
            }

            $onProgress = function (int $progress, string $message) use ($tasks, $task): void {
                $tasks->updateProgress($task, $progress, $message);
            };

            $tasks->updateProgress($task, 10, 'Loading data…');
            $result = $paginator->fetchAll($path, $searchParams, $user, 200, 10000, $onProgress, $task);
            $tasks->updateProgress($task, 95, 'Almost done…');
            $tasks->markCompleted($task, $result);
        } catch (\Throwable $e) {
            if ($task->fresh()?->status === 'cancelled') {
                return;
            }
            Log::warning('ReportRunJob failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
            $tasks->markFailed($task, $e->getMessage());
            throw $e;
        }
    }
}
