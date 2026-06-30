<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Background\BackgroundTaskService;
use App\Services\Background\ImportPayloadStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait QueuesImportBackgroundTask
{
    use ValidatesImportRequest;

    protected function queueImportBackgroundTask(
        Request $request,
        string $type,
        string $jobClass,
        string $queuedMessage,
        int $maxRows = 5000,
    ): JsonResponse {
        $data = $this->validateImportRows($request, $maxRows);
        // Keep rows in the background_tasks payload (DB) so queue workers do not depend on local disk.
        $payload = app(ImportPayloadStorage::class)->payloadForRows($data['rows'], $maxRows);

        $task = app(BackgroundTaskService::class)->createFromRequest($type, $request, $payload);
        $jobClass::dispatch($task->id);

        return response()->json([
            'message' => $queuedMessage,
            'task_id' => $task->id,
        ], 202);
    }
}
