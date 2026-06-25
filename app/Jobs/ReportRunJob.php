<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Background\InternalApiPaginator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

class ReportRunJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use RunsBackgroundTaskOnce;

    public int $timeout = 1800;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(BackgroundTaskService $tasks, InternalApiPaginator $paginator): void
    {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($this->shouldSkipBackgroundTask($task)) {
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
                $this->reportProgress($tasks, $task, $progress, $message);
            };

            $tasks->updateProgress($task, 10, 'Loading data…');
            $result = $paginator->fetchAll($path, $searchParams, $user, 500, 10000, $onProgress, $task);
            $tasks->assertNotCancelled($task);
            $tasks->updateProgress($task, 95, 'Almost done…');
            $tasks->markCompleted($task, $result);
        } catch (\Throwable $e) {
            $this->failBackgroundTask($tasks, $task, $e, 'ReportRunJob');
        }
    }
}
