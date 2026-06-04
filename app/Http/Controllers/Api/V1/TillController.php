<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Till;

class TillController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Till::class;
    }
}
