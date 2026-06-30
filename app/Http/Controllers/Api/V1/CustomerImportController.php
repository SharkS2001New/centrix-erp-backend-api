<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Concerns\QueuesImportBackgroundTask;
use App\Http\Controllers\Controller;
use App\Jobs\ImportCustomersJob;
use Illuminate\Http\Request;

class CustomerImportController extends Controller
{
    use EnsuresAdvancedDataImport;
    use QueuesImportBackgroundTask;

    /** POST /customers/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request);

        return $this->queueImportBackgroundTask(
            $request,
            'customer_import',
            ImportCustomersJob::class,
            'Customer import queued.',
        );
    }
}
