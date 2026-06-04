<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\PayPeriod;

class PayPeriodController extends BaseResourceController
{
    protected function modelClass(): string { return PayPeriod::class; }
}
