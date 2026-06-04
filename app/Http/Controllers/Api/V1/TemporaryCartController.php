<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\TemporaryCart;

class TemporaryCartController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return TemporaryCart::class;
    }
}
