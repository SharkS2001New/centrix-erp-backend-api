<?php

namespace App\Services\Inventory;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\CapabilityGate;

class SaleStockReservationService
{
    use HandlesInventory;

    public function reserveIfNeeded(Sale $sale, User $user, CapabilityGate $gate): void
    {
        $this->reserveSaleStockIfNeeded($sale, $user, $gate);
    }

    public function releaseForSale(int $saleId): void
    {
        $this->releaseSaleReservations($saleId);
    }

    public function transferFromCart(int $cartId, int $saleId): void
    {
        $this->transferCartReservationsToSale($cartId, $saleId);
    }

    public function releaseCart(int $cartId): void
    {
        $this->releaseCartReservations($cartId);
    }
}
