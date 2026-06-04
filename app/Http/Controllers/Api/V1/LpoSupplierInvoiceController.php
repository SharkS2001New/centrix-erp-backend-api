<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\LpoSupplierInvoice;

class LpoSupplierInvoiceController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return LpoSupplierInvoice::class;
    }
}
