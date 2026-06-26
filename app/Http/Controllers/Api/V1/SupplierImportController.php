<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ImportSuppliersJob;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Http\Request;

class SupplierImportController extends Controller
{
    public function __construct(
        protected BackgroundTaskService $tasks,
    ) {}

    /** POST /suppliers/import-batch */
    public function store(Request $request)
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*.supplier_name' => ['nullable', 'string', 'max:200'],
        ]);

        $user = $request->user();
        $task = $this->tasks->create('supplier_import', $user, [
            'rows' => $data['rows'],
        ]);

        ImportSuppliersJob::dispatch($task->id);

        return response()->json([
            'message' => 'Supplier import queued.',
            'task_id' => $task->id,
        ], 202);
    }
}
