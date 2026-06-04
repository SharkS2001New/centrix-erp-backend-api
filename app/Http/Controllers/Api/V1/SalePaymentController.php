<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\SalePayment;

class SalePaymentController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return SalePayment::class;
    }
}
