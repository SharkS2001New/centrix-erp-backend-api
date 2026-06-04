<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\PayrollRun;

class PayrollRunController extends BaseResourceController
{
    protected function modelClass(): string { return PayrollRun::class; }
}
