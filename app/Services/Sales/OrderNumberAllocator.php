<?php

namespace App\Services\Sales;

use App\Models\Sale;

class OrderNumberAllocator
{
    /** Legacy LightStores import reserves order_num >= 1_000_000 per sales channel. */
    public const LEGACY_IMPORTED_ORDER_NUM_MIN = 1_000_000;

    public function nextForOrganization(int $organizationId): int
    {
        $max = Sale::query()
            ->where('organization_id', $organizationId)
            ->where('order_num', '<', self::LEGACY_IMPORTED_ORDER_NUM_MIN)
            ->max('order_num');

        return (int) ($max ?? 0) + 1;
    }
}
