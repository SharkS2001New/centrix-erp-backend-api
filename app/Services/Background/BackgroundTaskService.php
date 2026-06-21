<?php

namespace App\Services\Background;

use App\Models\BackgroundTask;
use App\Models\User;

class BackgroundTaskService
{
    public function create(string $type, User $user, array $payload = []): BackgroundTask
    {
        return BackgroundTask::createPending(
            $type,
            (int) $user->organization_id,
            (int) $user->id,
            $payload,
        );
    }

    public function markRunning(BackgroundTask $task): void
    {
        $task->update([
            'status' => 'running',
            'progress' => max(1, (int) $task->progress),
            'started_at' => now(),
            'error_message' => null,
        ]);
    }

    public function updateProgress(BackgroundTask $task, int $progress): void
    {
        $task->update([
            'progress' => max(0, min(100, $progress)),
        ]);
    }

    public function markCompleted(BackgroundTask $task, array $result = []): void
    {
        $task->update([
            'status' => 'completed',
            'progress' => 100,
            'result' => $result,
            'finished_at' => now(),
        ]);
    }

    public function markFailed(BackgroundTask $task, string $message): void
    {
        $task->update([
            'status' => 'failed',
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }

    public function findForUser(string $id, User $user): ?BackgroundTask
    {
        return BackgroundTask::query()
            ->where('id', $id)
            ->where('organization_id', (int) $user->organization_id)
            ->first();
    }
}
