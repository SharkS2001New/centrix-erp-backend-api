<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Concerns\QueuesImportBackgroundTask;
use App\Http\Controllers\Controller;
use App\Jobs\ImportSuppliersJob;
use Illuminate\Http\Request;

class SupplierImportController extends Controller
{
    use EnsuresAdvancedDataImport;
    use QueuesImportBackgroundTask;

    /** POST /suppliers/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request, 'suppliers');

        return $this->queueImportBackgroundTask(
            $request,
            'supplier_import',
            ImportSuppliersJob::class,
            'Supplier import queued.',
        );
    }
}
