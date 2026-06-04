<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\CartLine;

class CartLineController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return CartLine::class;
    }
}
