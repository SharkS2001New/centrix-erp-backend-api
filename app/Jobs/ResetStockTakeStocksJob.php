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

class ResetStockTakeStocksJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(BackgroundTaskService $tasks, StockTakeOperationsController $operations): void
    {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($task === null || ! $tasks->markRunning($task)) {
            return;
        }

        try {
            $sessionId = (int) ($task->payload['session_id'] ?? 0);
            if ($sessionId <= 0) {
                throw new InvalidArgumentException('Stock take session id is required.');
            }

            $userId = (int) ($task->payload['user_id'] ?? $task->user_id);
            $user = User::query()->find($userId);
            if ($user === null) {
                throw new InvalidArgumentException('User not found for stock take reset task.');
            }

            $session = StockTakeSession::query()->findOrFail($sessionId);

            $tasks->updateProgress($task, 15);
            $result = $operations->resetStockTakeStocksSync($session, $user);
            $tasks->updateProgress($task, 95);

            $tasks->markCompleted($task, $result);
        } catch (\Throwable $e) {
            Log::warning('ResetStockTakeStocksJob failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
            $tasks->markFailed($task, $e->getMessage());
            throw $e;
        }
    }
}
