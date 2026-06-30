<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Concerns\QueuesImportBackgroundTask;
use App\Http\Controllers\Controller;
use App\Jobs\ImportVatsJob;
use Illuminate\Http\Request;

class VatImportController extends Controller
{
    use EnsuresAdvancedDataImport;
    use QueuesImportBackgroundTask;

    /** POST /vats/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request);

        return $this->queueImportBackgroundTask(
            $request,
            'vat_import',
            ImportVatsJob::class,
            'VAT import queued.',
        );
    }
}
