<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Controller;
use App\Jobs\ImportSubCategoriesJob;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Http\Request;

class SubCategoryImportController extends Controller
{
    use EnsuresAdvancedDataImport;

    public function __construct(
        protected BackgroundTaskService $tasks,
    ) {}

    /** POST /sub-categories/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request);

        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*.subcategory_name' => ['nullable', 'string', 'max:255'],
        ]);

        $task = $this->tasks->createFromRequest('subcategory_import', $request, [
            'rows' => $data['rows'],
        ]);

        ImportSubCategoriesJob::dispatch($task->id);

        return response()->json([
            'message' => 'Sub-category import queued.',
            'task_id' => $task->id,
        ], 202);
    }
}
