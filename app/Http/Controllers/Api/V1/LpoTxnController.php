<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\LpoTxn;

class LpoTxnController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return LpoTxn::class;
    }
}
