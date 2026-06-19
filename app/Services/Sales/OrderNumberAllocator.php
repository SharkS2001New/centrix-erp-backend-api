<?php

namespace App\Services\Sales;

use App\Models\Sale;

class OrderNumberAllocator
{
    public function nextForOrganization(int $organizationId): int
    {
        $max = Sale::query()
            ->where('organization_id', $organizationId)
            ->max('order_num');

        return (int) ($max ?? 0) + 1;
    }
}
