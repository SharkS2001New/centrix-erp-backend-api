<?php

namespace App\Services\Sales;

use App\Models\Organization;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

class OrderNumberAllocator
{
    /** Legacy LightStores import reserves order_num >= 1_000_000 per sales channel. */
    public const LEGACY_IMPORTED_ORDER_NUM_MIN = 1_000_000;

    /** Reserved range for cancelled sales superseded by POS edit (sale IDs are globally unique). */
    public const SUPERSEDED_ORDER_NUM_BASE = 9_000_000;

    public function tombstoneForSupersededSale(int $saleId): int
    {
        return self::SUPERSEDED_ORDER_NUM_BASE + $saleId;
    }

    public function nextForOrganization(int $organizationId): int
    {
        return DB::transaction(function () use ($organizationId): int {
            // Serialize order number allocation per organization so concurrent checkouts
            // cannot read the same max(order_num) and collide on insert.
            Organization::query()
                ->whereKey($organizationId)
                ->lockForUpdate()
                ->first();

            $last = Sale::query()
                ->where('organization_id', $organizationId)
                ->where('order_num', '<', self::LEGACY_IMPORTED_ORDER_NUM_MIN)
                ->orderByDesc('order_num')
                ->lockForUpdate()
                ->value('order_num');

            return (int) ($last ?? 0) + 1;
        });
    }
}
