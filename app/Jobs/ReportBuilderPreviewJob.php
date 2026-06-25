<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Reports\ReportBuilderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

class ReportBuilderPreviewJob implements ShouldQueue
{
    use Queueable;
    use RunsBackgroundTaskOnce;

    public int $timeout = 1800;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(BackgroundTaskService $tasks, ReportBuilderService $builder): void
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
                throw new InvalidArgumentException('User not found for report builder preview task.');
            }

            $payload = is_array($task->payload) ? $task->payload : [];
            $spec = $payload['spec'] ?? null;
            if (! is_array($spec)) {
                throw new InvalidArgumentException('Report spec is required.');
            }

            $workspaceId = isset($payload['workspace_id']) ? (string) $payload['workspace_id'] : null;
            $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];

            $this->reportProgress($tasks, $task, 25, 'Loading data…');
            $validatedSpec = $builder->validateSpec($spec, $workspaceId);
            $tasks->assertNotCancelled($task);

            $this->reportProgress($tasks, $task, 55, 'Please wait…');
            $result = $builder->run($user, $validatedSpec, $filters, $workspaceId);
            $tasks->assertNotCancelled($task);
            $this->reportProgress($tasks, $task, 92, 'Almost done…');

            $rows = $result['data'] ?? [];
            if (! is_array($rows)) {
                $rows = [];
            }

            $tasks->markCompleted($task, [
                'data' => $rows,
                'row_count' => count($rows),
                'meta' => $result['meta'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->failBackgroundTask($tasks, $task, $e, 'ReportBuilderPreviewJob');
        }
    }
}
