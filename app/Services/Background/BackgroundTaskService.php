<?php

namespace App\Services\Background;

use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackgroundTaskService
{
    public function __construct(
        protected BackgroundTaskQueueService $queue,
    ) {}

    public function create(string $type, User $user, array $payload = [], ?int $organizationId = null): BackgroundTask
    {
        $resolvedOrganizationId = $organizationId > 0
            ? $organizationId
            : (int) $user->organization_id;

        return BackgroundTask::createPending(
            $type,
            $resolvedOrganizationId,
            (int) $user->id,
            $payload,
        );
    }

    public function createFromRequest(string $type, Request $request, array $payload = []): BackgroundTask
    {
        $user = $request->user();
        abort_unless($user, 403);

        $organization = app(ErpContext::class)->resolveOrganization($request);

        return $this->create($type, $user, $payload, (int) $organization->id);
    }

    /**
     * Push the job onto the queue. Optionally (see background.inline_fallback) run it
     * inline after the HTTP response if a worker has not claimed it yet — local/sync
     * only by default. Production Redis should rely on queue workers; inline sync of
     * large exports exhausts API workers and surfaces as client "offline" errors.
     *
     * @param  class-string  $jobClass
     */
    public function dispatch(string $jobClass, BackgroundTask $task): void
    {
        $taskId = $task->id;
        // Queue workers pick this up while the HTTP 202 is in flight.
        // Skip dispatch() on the sync driver outside tests — that would run the
        // whole job before the client can poll, so the UI stays on "Starting…".
        if (app()->runningUnitTests() || config('queue.default') !== 'sync') {
            $jobClass::dispatch($taskId);
        }

        if (app()->runningUnitTests() || ! $this->shouldInlineFallback()) {
            return;
        }

        app()->terminating(function () use ($jobClass, $taskId): void {
            $delayMs = max(0, (int) config('background.inline_fallback_delay_ms', 750));
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $fresh = BackgroundTask::query()->find($taskId);
            if ($fresh === null || $fresh->status !== 'pending') {
                return;
            }

            set_time_limit(0);
            ignore_user_abort(true);

            try {
                $jobClass::dispatchSync($taskId);
            } catch (\Throwable $e) {
                Log::warning('Background task inline fallback failed', [
                    'task_id' => $taskId,
                    'job' => $jobClass,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    protected function shouldInlineFallback(): bool
    {
        $configured = config('background.inline_fallback');
        if ($configured !== null && $configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        }

        return config('queue.default') === 'sync';
    }

    /**
     * Atomically claim a pending task. Returns false if another runner already claimed
     * it or the task is no longer pending.
     */
    public function markRunning(BackgroundTask $task): bool
    {
        $affected = BackgroundTask::query()
            ->whereKey($task->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'running',
                'progress' => max(1, (int) $task->progress),
                'started_at' => now(),
                'error_message' => null,
            ]);

        if ($affected === 0) {
            $task->refresh();

            return false;
        }

        $task->refresh();

        return true;
    }

    /**
     * Reset a running task to pending so Laravel job retries can claim it again
     * (used for capped KRA product-registration auto-retries).
     */
    public function requeueForAutoRetry(BackgroundTask $task, int $attempt, ?string $message = null): void
    {
        if ($this->isTerminal($task)) {
            return;
        }

        $payload = is_array($task->payload) ? $task->payload : [];
        $payload['auto_retry_count'] = max((int) ($payload['auto_retry_count'] ?? 0), $attempt);
        $payload['auto_retry_last_at'] = now()->toIso8601String();
        $payload['progress_message'] = $message
            ?: "Auto-retry {$attempt}/".\App\Services\Kra\KraAutoRetryPolicy::maxAttempts().' scheduled…';

        BackgroundTask::query()->whereKey($task->id)->update([
            'status' => 'pending',
            'progress' => 0,
            'started_at' => null,
            'finished_at' => null,
            'error_message' => null,
            'payload' => $payload,
        ]);
        $task->refresh();
    }

    public function updateProgress(BackgroundTask $task, int $progress, ?string $message = null, ?int $processed = null, ?int $total = null): void
    {
        if ($this->isCancelled($task)) {
            throw new \RuntimeException('Background task was cancelled.');
        }

        $updates = [
            'progress' => max(0, min(100, $progress)),
        ];

        $payload = is_array($task->payload) ? $task->payload : [];
        $payloadChanged = false;
        if ($message !== null && $message !== '') {
            $payload['progress_message'] = $message;
            $payloadChanged = true;
        }
        if ($processed !== null) {
            $payload['processed'] = max(0, $processed);
            $payloadChanged = true;
        }
        if ($total !== null) {
            $payload['total'] = max(0, $total);
            $payloadChanged = true;
        }
        if ($payloadChanged) {
            $updates['payload'] = $payload;
        }

        $task->update($updates);
    }

    public function markCancelled(BackgroundTask $task, string $message = 'Cancelled by user.'): void
    {
        if ($this->isTerminal($task)) {
            return;
        }

        $task->update([
            'status' => 'cancelled',
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }

    /**
     * Cancel a task for the user and stop any queued worker job from running.
     */
    public function cancelTask(BackgroundTask $task, string $message = 'Cancelled by user.'): bool
    {
        $fresh = $task->fresh();
        if ($fresh === null || $this->isTerminal($fresh)) {
            return false;
        }

        $updated = DB::transaction(function () use ($fresh, $message) {
            $locked = BackgroundTask::query()
                ->whereKey($fresh->id)
                ->whereNotIn('status', ['completed', 'failed', 'cancelled'])
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }

            $locked->update([
                'status' => 'cancelled',
                'error_message' => $message,
                'finished_at' => now(),
            ]);

            return true;
        });

        if ($updated) {
            $this->queue->removePendingJobs($task->id);
        }

        return $updated;
    }

    public function isCancelled(BackgroundTask $task): bool
    {
        return $task->fresh()?->status === 'cancelled';
    }

    public function assertNotCancelled(BackgroundTask $task): void
    {
        if ($this->isCancelled($task)) {
            throw new \RuntimeException('Background task was cancelled.');
        }
    }

    public function markCompleted(BackgroundTask $task, array $result = []): void
    {
        if ($this->isTerminal($task)) {
            return;
        }

        $task->update([
            'status' => 'completed',
            'progress' => 100,
            'result' => $result,
            'finished_at' => now(),
        ]);
    }

    public function markFailed(BackgroundTask $task, string $message, array $result = []): void
    {
        if ($this->isTerminal($task)) {
            return;
        }

        $updates = [
            'status' => 'failed',
            'error_message' => $message,
            'finished_at' => now(),
        ];
        if ($result !== []) {
            $updates['result'] = $result;
        }

        $task->update($updates);
    }

    public function findForUser(string $id, User $user): ?BackgroundTask
    {
        return BackgroundTask::query()
            ->where('id', $id)
            ->where('user_id', (int) $user->id)
            ->where('organization_id', (int) $user->organization_id)
            ->first();
    }

    public function recoverStaleTasksForUser(User $user): int
    {
        $tasks = BackgroundTask::query()
            ->where('user_id', $user->id)
            ->where('organization_id', (int) $user->organization_id)
            ->whereIn('status', ['pending', 'running'])
            ->get();

        $recovered = 0;
        foreach ($tasks as $task) {
            if ($this->recoverStaleTask($task)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    public function recoverStaleTasks(): int
    {
        $tasks = BackgroundTask::query()
            ->whereIn('status', ['pending', 'running'])
            ->get();

        $recovered = 0;
        foreach ($tasks as $task) {
            if ($this->recoverStaleTask($task)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    public function assertNoBlockingTask(User $user): void
    {
        $this->recoverStaleTasksForUser($user);

        $hasActiveTask = BackgroundTask::query()
            ->where('user_id', $user->id)
            ->where('organization_id', (int) $user->organization_id)
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        abort_if(
            $hasActiveTask,
            409,
            'Another background task is already running. Wait for it to finish or cancel it from the task panel.',
        );
    }

    protected function recoverStaleTask(BackgroundTask $task): bool
    {
        if ($this->isTerminal($task)) {
            return false;
        }

        if (! $this->isStale($task)) {
            return false;
        }

        $this->markFailed(
            $task,
            'Background task timed out and was marked as failed. You can start a new export.',
        );

        return true;
    }

    protected function isStale(BackgroundTask $task): bool
    {
        $pendingMinutes = max(1, (int) config('background.stale_pending_minutes', 15));
        $runningMinutes = $this->staleRunningMinutesFor($task);
        $now = now();

        if ($task->status === 'pending') {
            return $this->isOlderThan($task->created_at, $pendingMinutes, $now);
        }

        if ($task->status === 'running') {
            $anchor = $task->started_at ?? $task->updated_at ?? $task->created_at;

            return $this->isOlderThan($anchor, $runningMinutes, $now);
        }

        return false;
    }

    protected function staleRunningMinutesFor(BackgroundTask $task): int
    {
        $default = max(1, (int) config('background.stale_running_minutes', 70));
        $type = (string) ($task->type ?? '');

        if (str_ends_with($type, '_import')) {
            return max(
                $default,
                (int) config('background.stale_import_running_minutes', 90),
            );
        }

        $longMinutes = max(
            (int) config('background.stale_export_running_minutes', 150),
            (int) config('background.stale_long_running_minutes', 150),
        );

        if (
            str_contains($type, 'export')
            || str_contains($type, 'report')
            || str_contains($type, 'paginated')
            || str_contains($type, 'backup')
            || str_contains($type, 'stock_take')
            || str_contains($type, 'kra')
            || str_contains($type, 'payroll')
            || str_contains($type, 'accounting')
        ) {
            return max($default, $longMinutes);
        }

        return $default;
    }

    protected function isOlderThan(?Carbon $timestamp, int $minutes, Carbon $now): bool
    {
        if ($timestamp === null) {
            return true;
        }

        return $timestamp->lte($now->copy()->subMinutes($minutes));
    }

    protected function isTerminal(?BackgroundTask $task): bool
    {
        return $task === null
            || in_array($task->fresh()?->status ?? $task->status, ['completed', 'failed', 'cancelled'], true);
    }
}
