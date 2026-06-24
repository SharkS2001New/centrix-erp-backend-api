<?php

namespace App\Jobs;

use App\Http\Controllers\Api\V1\Operations\StockTakeOperationsController;
use App\Models\BackgroundTask;
use App\Models\StockTakeSession;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class InitializeStockTakeSessionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(BackgroundTaskService $tasks): void
    {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($task === null) {
            return;
        }

        $tasks->markRunning($task);

        try {
            $sessionId = (int) ($task->payload['session_id'] ?? 0);
            if ($sessionId <= 0) {
                throw new InvalidArgumentException('Stock take session id is required.');
            }

            $user = User::query()->find($task->user_id);
            if ($user === null) {
                throw new InvalidArgumentException('User not found for stock take initialize task.');
            }

            $session = StockTakeSession::query()->findOrFail($sessionId);
            $request = Request::create('/', 'POST');
            $request->setUserResolver(fn () => $user);

            $operations = app(StockTakeOperationsController::class);

            $tasks->updateProgress($task, 15);
            $result = $operations->initializeStockTakeLines($session, $request);
            $tasks->updateProgress($task, 95);

            $tasks->markCompleted($task, $result);
        } catch (\Throwable $e) {
            Log::warning('InitializeStockTakeSessionJob failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
            $tasks->markFailed($task, $e->getMessage());
            throw $e;
        }
    }
}
