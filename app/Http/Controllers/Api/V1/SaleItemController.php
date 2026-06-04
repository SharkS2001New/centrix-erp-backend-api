<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\SaleItem;

class SaleItemController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return SaleItem::class;
    }
}
