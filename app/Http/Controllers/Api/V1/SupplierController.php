<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Supplier;

class SupplierController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Supplier::class;
    }
}
