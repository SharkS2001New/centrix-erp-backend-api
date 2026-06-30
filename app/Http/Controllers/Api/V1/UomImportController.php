<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Controller;
use App\Jobs\ImportUomsJob;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Http\Request;

class UomImportController extends Controller
{
    use EnsuresAdvancedDataImport;

    public function __construct(
        protected BackgroundTaskService $tasks,
    ) {}

    /** POST /uoms/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request);

        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*.measure_name' => ['nullable', 'string', 'max:255'],
        ]);

        $task = $this->tasks->createFromRequest('uom_import', $request, [
            'rows' => $data['rows'],
        ]);

        ImportUomsJob::dispatch($task->id);

        return response()->json([
            'message' => 'UOM import queued.',
            'task_id' => $task->id,
        ], 202);
    }
}
