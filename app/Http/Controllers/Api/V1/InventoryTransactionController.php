<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\InventoryTransaction;
use Illuminate\Http\Request;

class InventoryTransactionController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return InventoryTransaction::class;
    }

    protected function baseQuery(Request $request)
    {
        return parent::baseQuery($request)
            ->with(['product:product_code,product_name,unit_id']);
    }
}
