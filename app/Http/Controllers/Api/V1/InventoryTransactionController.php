<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\InventoryTransaction;

class InventoryTransactionController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return InventoryTransaction::class;
    }
}
