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
            'result' => $task->result,
            'error_message' => $task->error_message,
            'started_at' => $task->started_at,
            'finished_at' => $task->finished_at,
            'created_at' => $task->created_at,
        ]);
    }
}
