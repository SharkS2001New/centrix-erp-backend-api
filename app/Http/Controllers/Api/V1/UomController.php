<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Uom;

class UomController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Uom::class;
    }
}
