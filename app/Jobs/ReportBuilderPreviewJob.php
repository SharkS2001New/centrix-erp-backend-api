<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Reports\ReportBuilderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ReportBuilderPreviewJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(BackgroundTaskService $tasks, ReportBuilderService $builder): void
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
                throw new InvalidArgumentException('User not found for report builder preview task.');
            }

            $payload = is_array($task->payload) ? $task->payload : [];
            $spec = $payload['spec'] ?? null;
            if (! is_array($spec)) {
                throw new InvalidArgumentException('Report spec is required.');
            }

            $workspaceId = isset($payload['workspace_id']) ? (string) $payload['workspace_id'] : null;
            $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];

            $tasks->updateProgress($task, 25, 'Loading data…');
            $validatedSpec = $builder->validateSpec($spec, $workspaceId);
            $tasks->assertNotCancelled($task);

            $tasks->updateProgress($task, 55, 'Please wait…');
            $result = $builder->run($user, $validatedSpec, $filters, $workspaceId);
            $tasks->updateProgress($task, 92, 'Almost done…');

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
            if ($task->fresh()?->status === 'cancelled') {
                return;
            }
            Log::warning('ReportBuilderPreviewJob failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
            $tasks->markFailed($task, $e->getMessage());
            throw $e;
        }
    }
}
