<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Controller;
use App\Jobs\ImportRoutesJob;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Http\Request;

class RouteImportController extends Controller
{
    use EnsuresAdvancedDataImport;

    public function __construct(
        protected BackgroundTaskService $tasks,
    ) {}

    /** POST /routes/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request);

        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*.route_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $task = $this->tasks->create('route_import', $user, [
            'rows' => $data['rows'],
        ]);

        ImportRoutesJob::dispatch($task->id);

        return response()->json([
            'message' => 'Route import queued.',
            'task_id' => $task->id,
        ], 202);
    }
}
