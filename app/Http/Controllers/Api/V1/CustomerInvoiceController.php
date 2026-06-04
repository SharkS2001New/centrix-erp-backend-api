<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\CustomerInvoice;

class CustomerInvoiceController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return CustomerInvoice::class;
    }
}
