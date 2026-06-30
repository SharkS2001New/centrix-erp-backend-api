<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Controller;
use App\Jobs\ImportCategoriesJob;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Http\Request;

class CategoryImportController extends Controller
{
    use EnsuresAdvancedDataImport;

    public function __construct(
        protected BackgroundTaskService $tasks,
    ) {}

    /** POST /categories/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request);

        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*.category_name' => ['nullable', 'string', 'max:255'],
        ]);

        $task = $this->tasks->createFromRequest('category_import', $request, [
            'rows' => $data['rows'],
        ]);

        ImportCategoriesJob::dispatch($task->id);

        return response()->json([
            'message' => 'Category import queued.',
            'task_id' => $task->id,
        ], 202);
    }
}
