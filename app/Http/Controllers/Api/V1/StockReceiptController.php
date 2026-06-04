<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\StockReceipt;

class StockReceiptController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return StockReceipt::class;
    }
}
