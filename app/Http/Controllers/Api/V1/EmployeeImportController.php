<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Controller;
use App\Jobs\ImportEmployeesJob;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Http\Request;

class EmployeeImportController extends Controller
{
    use EnsuresAdvancedDataImport;

    public function __construct(
        protected BackgroundTaskService $tasks,
    ) {}

    /** POST /employees/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request);

        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*.first_name' => ['nullable', 'string', 'max:100'],
            'rows.*.last_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $task = $this->tasks->create('employee_import', $user, [
            'rows' => $data['rows'],
        ]);

        ImportEmployeesJob::dispatch($task->id);

        return response()->json([
            'message' => 'Employee import queued.',
            'task_id' => $task->id,
        ], 202);
    }
}
