<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Background\InternalApiPaginator;
use App\Services\Background\ReportExportSearchParams;
use App\Services\Background\ReportFetchResultBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

class PaginatedFetchJob implements ShouldQueue
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
        if ($this->shouldSkipBackgroundTask($task) || ! $tasks->markRunning($task)) {
            return;
        }

        try {
            $user = User::query()->find($task->user_id);
            if ($user === null) {
                throw new InvalidArgumentException('User not found for paginated fetch task.');
            }

            $path = (string) ($task->payload['path'] ?? '');
            $searchParams = $task->payload['search_params'] ?? [];
            if ($path === '') {
                throw new InvalidArgumentException('API path is required for paginated fetch.');
            }
            if (! is_array($searchParams)) {
                $searchParams = [];
            }
            $searchParams = ReportExportSearchParams::sanitize($searchParams);

            $builder = ReportFetchResultBuilder::forTask($task);

            $tasks->updateProgress($task, 10, 'Started fetching…');
            $result = $paginator->eachPage(
                $path,
                $searchParams,
                $user,
                static function (array $batch) use ($builder): void {
                    $builder->appendRows($batch);
                },
                null,
                null,
                function (int $progress, string $message, ?int $processed = null, ?int $total = null) use ($tasks, $task): void {
                    $this->reportProgress($tasks, $task, $progress, $message, $processed, $total);
                },
                $task,
            );
            $tasks->assertNotCancelled($task);
            $tasks->updateProgress($task, 95, 'Almost done…');

            $tasks->markCompleted($task, $builder->finalize($result['truncated']));
        } catch (\Throwable $e) {
            $this->failBackgroundTask($tasks, $task, $e, 'PaginatedFetchJob');
        }
    }
}
