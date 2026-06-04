<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\ReturnRecord;

class ReturnRecordController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return ReturnRecord::class;
    }
}
