<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Controller;
use App\Jobs\ImportVatsJob;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Http\Request;

class VatImportController extends Controller
{
    use EnsuresAdvancedDataImport;

    public function __construct(
        protected BackgroundTaskService $tasks,
    ) {}

    /** POST /vats/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request);

        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*.vat_code' => ['nullable', 'string', 'max:50'],
            'rows.*.vat_name' => ['nullable', 'string', 'max:255'],
        ]);

        $task = $this->tasks->createFromRequest('vat_import', $request, [
            'rows' => $data['rows'],
        ]);

        ImportVatsJob::dispatch($task->id);

        return response()->json([
            'message' => 'VAT import queued.',
            'task_id' => $task->id,
        ], 202);
    }
}
