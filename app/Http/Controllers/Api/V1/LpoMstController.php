<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\LpoMst;

class LpoMstController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return LpoMst::class;
    }
}
