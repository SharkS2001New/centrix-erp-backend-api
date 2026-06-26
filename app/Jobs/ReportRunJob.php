<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Background\InternalApiPaginator;
use App\Services\Background\ReportExportSearchParams;
use App\Services\Background\ReportFetchResultBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

class ReportRunJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use RunsBackgroundTaskOnce;

    public int $timeout = 3600;

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
            $searchParams = ReportExportSearchParams::sanitize($searchParams);

            $builder = ReportFetchResultBuilder::forTask($task);

            $onProgress = function (int $progress, string $message) use ($tasks, $task): void {
                $this->reportProgress($tasks, $task, $progress, $message);
            };

            $tasks->updateProgress($task, 10, 'Loading data…');
            $result = $paginator->eachPage(
                $path,
                $searchParams,
                $user,
                static function (array $batch) use ($builder): void {
                    $builder->appendRows($batch);
                },
                null,
                null,
                $onProgress,
                $task,
            );

            $tasks->assertNotCancelled($task);
            $tasks->updateProgress($task, 95, 'Almost done…');
            $tasks->markCompleted($task, $builder->finalize($result['truncated']));
        } catch (\Throwable $e) {
            $this->failBackgroundTask($tasks, $task, $e, 'ReportRunJob');
        }
    }
}
