<?php

namespace App\Jobs;

use App\Http\Controllers\Api\V1\Operations\KraOperationsController;
use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReFiscalizeKraInvoicesLiveJob implements ShouldQueue
{
    use Queueable;
    use RunsBackgroundTaskOnce;

    public int $timeout;

    public function __construct(
        public string $taskId,
    ) {
        $this->timeout = max(
            3600,
            (int) config('background.job_timeout_long_seconds', 7200),
        );
    }

    public function handle(BackgroundTaskService $tasks): void
    {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($this->shouldSkipBackgroundTask($task) || ! $tasks->markRunning($task)) {
            return;
        }

        try {
            $user = User::query()->find($task->user_id);
            if ($user === null) {
                throw new \RuntimeException('User not found for live KRA re-fiscalization task.');
            }

            $responseIds = array_values(array_unique(array_filter(
                array_map('intval', (array) ($task->payload['kra_response_ids'] ?? [])),
                fn (int $id) => $id > 0,
            )));
            if ($responseIds === []) {
                throw new \RuntimeException('No eligible KRA invoices were included in the task.');
            }
            $organizationId = (int) ($task->payload['target_organization_id'] ?? $task->organization_id);
            if ($organizationId <= 0) {
                throw new \RuntimeException('Organization not found for live KRA re-fiscalization task.');
            }

            $total = count($responseIds);
            $succeeded = 0;
            $failed = [];
            $controller = app(KraOperationsController::class);

            foreach ($responseIds as $index => $responseId) {
                $tasks->assertNotCancelled($task);
                $request = Request::create(
                    "/api/v1/kra-responses/{$responseId}/re-fiscalize-live",
                    'POST',
                );
                $request->attributes->set('acting_organization_id', $organizationId);
                $request->setUserResolver(fn () => $user);

                try {
                    $response = $controller->reFiscalizeLive($request, $responseId);
                    $data = $response->getData(true);
                    if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                        $succeeded++;
                    } else {
                        $failed[] = [
                            'kra_response_id' => $responseId,
                            'message' => (string) ($data['message'] ?? 'Live re-fiscalization failed.'),
                        ];
                    }
                } catch (\Throwable $e) {
                    $message = trim($e->getMessage()) ?: 'Live re-fiscalization failed.';
                    $failed[] = [
                        'kra_response_id' => $responseId,
                        'message' => $message,
                    ];
                    Log::warning('Queued live KRA re-fiscalization failed', [
                        'task_id' => $task->id,
                        'kra_response_id' => $responseId,
                        'organization_id' => $organizationId,
                        'error' => $message,
                    ]);
                }

                $processed = $index + 1;
                $tasks->updateProgress(
                    $task,
                    (int) round(($processed / $total) * 100),
                    "Re-fiscalizing live invoice {$processed} of {$total}…",
                    $processed,
                    $total,
                );
            }

            $tasks->markCompleted($task, [
                'succeeded' => $succeeded,
                'failed' => count($failed),
                'failures' => $failed,
                'total' => $total,
            ]);
        } catch (\Throwable $e) {
            $this->failBackgroundTask($tasks, $task, $e, 'ReFiscalizeKraInvoicesLiveJob');
        }
    }
}
