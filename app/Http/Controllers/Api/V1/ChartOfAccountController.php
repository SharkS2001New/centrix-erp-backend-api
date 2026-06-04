<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\ChartOfAccount;

class ChartOfAccountController extends BaseResourceController
{
    protected function modelClass(): string { return ChartOfAccount::class; }
}
