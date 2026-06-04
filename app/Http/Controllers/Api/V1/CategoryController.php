<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Category;

class CategoryController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Category::class;
    }
}
