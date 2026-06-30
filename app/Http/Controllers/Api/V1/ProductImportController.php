<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Controller;
use App\Jobs\ImportProductsJob;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Http\Request;

class ProductImportController extends Controller
{
    use EnsuresAdvancedDataImport;

    public function __construct(
        protected BackgroundTaskService $tasks,
    ) {}

    /** POST /products/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request);

        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*.product_code' => ['nullable', 'string', 'max:200'],
            'rows.*.product_name' => ['nullable', 'string', 'max:255'],
            'rows.*.subcategory_id' => ['nullable'],
            'rows.*.unit_id' => ['nullable'],
            'rows.*.unit_price' => ['nullable'],
        ]);

        $user = $request->user();
        $task = $this->tasks->create('product_import', $user, [
            'rows' => $data['rows'],
        ]);

        ImportProductsJob::dispatch($task->id);

        return response()->json([
            'message' => 'Product import queued.',
            'task_id' => $task->id,
        ], 202);
    }
}
