<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Concerns\QueuesImportBackgroundTask;
use App\Http\Controllers\Controller;
use App\Jobs\ImportUomsJob;
use Illuminate\Http\Request;

class UomImportController extends Controller
{
    use EnsuresAdvancedDataImport;
    use QueuesImportBackgroundTask;

    /** POST /uoms/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request);

        return $this->queueImportBackgroundTask(
            $request,
            'uom_import',
            ImportUomsJob::class,
            'UOM import queued.',
        );
    }
}
