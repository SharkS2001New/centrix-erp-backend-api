<?php

namespace App\Services\Sales;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\Sale;
use App\Models\User;

/** Restores stock when an order is cancelled or expired. */
class SaleInventoryRestorer
{
    use HandlesInventory;

    public function restore(Sale $sale, User $user): void
    {
        $this->restoreCancelledSaleStock($sale, $user);
    }
}
