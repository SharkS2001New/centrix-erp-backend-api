<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Concerns\QueuesImportBackgroundTask;
use App\Http\Controllers\Controller;
use App\Jobs\ImportRetailPackagesJob;
use Illuminate\Http\Request;

class RetailPackageImportController extends Controller
{
    use EnsuresAdvancedDataImport;
    use QueuesImportBackgroundTask;

    /** POST /retail-package-settings/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request, 'retail_packages');

        return $this->queueImportBackgroundTask(
            $request,
            'retail_package_import',
            ImportRetailPackagesJob::class,
            'Retail package import queued.',
        );
    }
}
