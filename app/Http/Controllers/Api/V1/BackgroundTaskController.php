<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Http\Request;

class BackgroundTaskController extends Controller
{
    public function __construct(
        protected BackgroundTaskService $tasks,
    ) {}

    /** GET /background-tasks/{id} */
    public function show(Request $request, string $id)
    {
        $task = $this->tasks->findForUser($id, $request->user());
        abort_if($task === null, 404, 'Background task not found.');

        return response()->json([
            'id' => $task->id,
            'type' => $task->type,
            'status' => $task->status,
            'progress' => $task->progress,
            'progress_message' => $task->payload['progress_message'] ?? null,
            'result' => $task->result,
            'error_message' => $task->error_message,
            'started_at' => $task->started_at,
            'finished_at' => $task->finished_at,
            'created_at' => $task->created_at,
        ]);
    }

    /** POST /background-tasks/{id}/cancel */
    public function cancel(Request $request, string $id)
    {
        $task = $this->tasks->findForUser($id, $request->user());
        abort_if($task === null, 404, 'Background task not found.');

        if (in_array($task->status, ['completed', 'failed', 'cancelled'], true)) {
            return response()->json([
                'message' => 'Task is already finished.',
                'status' => $task->status,
            ], 422);
        }

        $this->tasks->markCancelled($task);

        return response()->json([
            'message' => 'Background task cancelled.',
            'status' => 'cancelled',
        ]);
    }

    /** GET /background-tasks/{id}/download */
    public function download(Request $request, string $id)
    {
        $task = $this->tasks->findForUser($id, $request->user());
        abort_if($task === null, 404, 'Background task not found.');
        abort_if($task->status !== 'completed', 422, 'Export is not ready yet.');

        $diskPath = $task->result['disk_path'] ?? null;
        abort_if(! is_string($diskPath) || $diskPath === '', 404, 'Export file not found.');

        $absolute = storage_path('app/'.$diskPath);
        abort_unless(is_file($absolute), 404, 'Export file is missing.');

        $filename = $task->result['filename'] ?? basename($diskPath);
        $mime = $task->result['mime_type'] ?? 'application/octet-stream';

        return response()->download($absolute, $filename, [
            'Content-Type' => $mime,
        ]);
    }
}
