<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\SubCategory;

class SubCategoryController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return SubCategory::class;
    }
}
