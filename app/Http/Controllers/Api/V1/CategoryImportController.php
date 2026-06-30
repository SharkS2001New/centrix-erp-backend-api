<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Concerns\QueuesImportBackgroundTask;
use App\Http\Controllers\Controller;
use App\Jobs\ImportCategoriesJob;
use Illuminate\Http\Request;

class CategoryImportController extends Controller
{
    use EnsuresAdvancedDataImport;
    use QueuesImportBackgroundTask;

    /** POST /categories/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request, 'categories');

        return $this->queueImportBackgroundTask(
            $request,
            'category_import',
            ImportCategoriesJob::class,
            'Category import queued.',
        );
    }
}
