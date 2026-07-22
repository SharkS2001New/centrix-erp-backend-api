<?php

namespace App\Jobs;

use App\Http\Controllers\Api\V1\Operations\StockTakeOperationsController;
use App\Models\BackgroundTask;
use App\Models\StockTakeSession;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class CompleteStockTakeSessionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(
        BackgroundTaskService $tasks,
        StockTakeOperationsController $operations,
    ): void {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($task === null) {
            return;
        }

        $tasks->markRunning($task);

        try {
            $sessionId = (int) ($task->payload['session_id'] ?? 0);
            $userId = (int) ($task->payload['user_id'] ?? 0);
            if ($sessionId <= 0 || $userId <= 0) {
                throw new InvalidArgumentException('Stock take complete payload is invalid.');
            }

            $session = StockTakeSession::query()->findOrFail($sessionId);
            $user = User::query()->findOrFail($userId);

            $tasks->updateProgress($task, 10);
            $completed = $operations->completeStockTakeSession($session, $user);
            $tasks->updateProgress($task, 95);

            $tasks->markCompleted($task, [
                'session_id' => $sessionId,
                'status' => $completed->status,
            ]);
        } catch (\Throwable $e) {
            Log::warning('CompleteStockTakeSessionJob failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
            $tasks->markFailed($task, $e->getMessage());
            throw $e;
        }
    }
}
