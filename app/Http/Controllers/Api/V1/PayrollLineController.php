<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\PayrollLine;

class PayrollLineController extends BaseResourceController
{
    protected function modelClass(): string { return PayrollLine::class; }
}
