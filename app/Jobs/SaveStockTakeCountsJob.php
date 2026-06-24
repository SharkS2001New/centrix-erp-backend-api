<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\StockTakeLine;
use App\Models\StockTakeSession;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SaveStockTakeCountsJob implements ShouldQueue
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
            $lines = $task->payload['lines'] ?? [];
            if ($sessionId <= 0 || ! is_array($lines) || count($lines) === 0) {
                throw new InvalidArgumentException('Stock take save payload is invalid.');
            }

            $session = StockTakeSession::query()->findOrFail($sessionId);
            if ($session->status === 'completed') {
                throw new InvalidArgumentException('Session already completed.');
            }

            $allowedIds = StockTakeLine::query()
                ->where('session_id', $sessionId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $allowedMap = array_fill_keys($allowedIds, true);

            $updated = 0;
            $total = count($lines);

            foreach ($lines as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $lineId = (int) ($line['id'] ?? 0);
                if ($lineId <= 0 || ! isset($allowedMap[$lineId])) {
                    continue;
                }

                StockTakeLine::query()
                    ->where('id', $lineId)
                    ->update([
                        'counted_quantity' => (float) ($line['counted_quantity'] ?? 0),
                    ]);
                $updated++;

                if ($total > 0 && ($index + 1) % max(1, (int) floor($total / 20)) === 0) {
                    $tasks->updateProgress($task, (int) floor((($index + 1) / $total) * 100));
                }
            }

            $tasks->markCompleted($task, [
                'session_id' => $sessionId,
                'updated' => $updated,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SaveStockTakeCountsJob failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
            $tasks->markFailed($task, $e->getMessage());
            throw $e;
        }
    }
}
