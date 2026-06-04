<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\CustomerInvoicePayment;

class CustomerInvoicePaymentController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return CustomerInvoicePayment::class;
    }
}
