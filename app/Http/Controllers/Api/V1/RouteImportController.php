<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Concerns\QueuesImportBackgroundTask;
use App\Http\Controllers\Controller;
use App\Jobs\ImportRoutesJob;
use Illuminate\Http\Request;

class RouteImportController extends Controller
{
    use EnsuresAdvancedDataImport;
    use QueuesImportBackgroundTask;

    /** POST /routes/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request);

        return $this->queueImportBackgroundTask(
            $request,
            'route_import',
            ImportRoutesJob::class,
            'Route import queued.',
        );
    }
}
