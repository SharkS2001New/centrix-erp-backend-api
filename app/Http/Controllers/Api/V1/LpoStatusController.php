<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\LpoStatus;

class LpoStatusController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return LpoStatus::class;
    }
}
