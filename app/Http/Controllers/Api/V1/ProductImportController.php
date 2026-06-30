<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Concerns\QueuesImportBackgroundTask;
use App\Http\Controllers\Controller;
use App\Jobs\ImportProductsJob;
use Illuminate\Http\Request;

class ProductImportController extends Controller
{
    use EnsuresAdvancedDataImport;
    use QueuesImportBackgroundTask;

    /** POST /products/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request, 'products');

        return $this->queueImportBackgroundTask(
            $request,
            'product_import',
            ImportProductsJob::class,
            'Product import queued.',
        );
    }
}
