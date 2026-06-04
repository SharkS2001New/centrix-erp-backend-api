<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\PaymentMethod;

class PaymentMethodController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return PaymentMethod::class;
    }
}
