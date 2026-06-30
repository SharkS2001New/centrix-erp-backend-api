<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresAdvancedDataImport;
use App\Http\Controllers\Concerns\QueuesImportBackgroundTask;
use App\Http\Controllers\Controller;
use App\Jobs\ImportSubCategoriesJob;
use Illuminate\Http\Request;

class SubCategoryImportController extends Controller
{
    use EnsuresAdvancedDataImport;
    use QueuesImportBackgroundTask;

    /** POST /sub-categories/import-batch */
    public function store(Request $request)
    {
        $this->ensureAdvancedDataImport($request);

        return $this->queueImportBackgroundTask(
            $request,
            'subcategory_import',
            ImportSubCategoriesJob::class,
            'Sub-category import queued.',
        );
    }
}
