<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Concerns\QueuesImportBackgroundTask;
use App\Http\Controllers\Controller;
use App\Jobs\ImportEmployeesJob;
use Illuminate\Http\Request;

class EmployeeImportController extends Controller
{
    use EnsuresAdvancedDataImport;
    use QueuesImportBackgroundTask;

    /** POST /employees/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request, 'employees');

        return $this->queueImportBackgroundTask(
            $request,
            'employee_import',
            ImportEmployeesJob::class,
            'Employee import queued.',
        );
    }
}
