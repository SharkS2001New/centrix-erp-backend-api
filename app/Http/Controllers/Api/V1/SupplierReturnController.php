<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\SupplierReturn;

class SupplierReturnController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return SupplierReturn::class;
    }
}
