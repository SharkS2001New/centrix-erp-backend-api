<?php

namespace App\Services;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\InventoryTransaction;

class LpoInventoryService
{
    use HandlesInventory;

    public function adjustStock(array $data): InventoryTransaction
    {
        return $this->postStockLedger($data);
    }
}
