<?php

namespace App\Services\Sales;

use App\Models\Organization;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderNumberAllocator
{
    /** Legacy LightStores import reserves order_num >= 1_000_000 per sales channel. */
    public const LEGACY_IMPORTED_ORDER_NUM_MIN = 1_000_000;

    /** Reserved range for cancelled sales superseded by POS edit (sale IDs are globally unique). */
    public const SUPERSEDED_ORDER_NUM_BASE = 9_000_000;

    /** Max numbers a single POS till may reserve in one request (short offline window). */
    public const MAX_RESERVE_BLOCK = 50;

    public function tombstoneForSupersededSale(int $saleId): int
    {
        return self::SUPERSEDED_ORDER_NUM_BASE + $saleId;
    }

    /**
     * Next order number preview for cart/UI — no locks.
     * Use {@see nextForOrganization()} only when actually allocating at checkout.
     */
    public function peekNextForOrganization(int $organizationId): int
    {
        return $this->ceilingForOrganization($organizationId) + 1;
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

            $this->lockWatermarkRow($organizationId);

            $next = $this->ceilingForOrganization($organizationId, locked: true) + 1;
            $this->writeWatermark($organizationId, $next);

            return $next;
        });
    }

    /**
     * Reserve a contiguous block of order numbers for offline External POS.
     *
     * @return array{start: int, end: int, numbers: list<int>}
     */
    public function reserveBlockForOrganization(int $organizationId, int $count): array
    {
        $count = max(1, min(self::MAX_RESERVE_BLOCK, $count));

        return DB::transaction(function () use ($organizationId, $count): array {
            // Prefer org row lock when present (production); watermark lock always serializes.
            Organization::query()
                ->whereKey($organizationId)
                ->lockForUpdate()
                ->first();

            $this->lockWatermarkRow($organizationId);

            $start = $this->ceilingForOrganization($organizationId, locked: true) + 1;
            $end = $start + $count - 1;
            $this->writeWatermark($organizationId, $end);

            $numbers = [];
            for ($n = $start; $n <= $end; $n++) {
                $numbers[] = $n;
            }

            return [
                'start' => $start,
                'end' => $end,
                'numbers' => $numbers,
            ];
        });
    }

    /**
     * Highest order number already claimed (sold or reserved) for the live range.
     */
    protected function ceilingForOrganization(int $organizationId, bool $locked = false): int
    {
        $saleQuery = Sale::query()
            ->where('organization_id', $organizationId)
            ->where('order_num', '<', self::LEGACY_IMPORTED_ORDER_NUM_MIN)
            ->orderByDesc('order_num');

        if ($locked) {
            $saleQuery->lockForUpdate();
        }

        $saleMax = (int) ($saleQuery->value('order_num') ?? 0);
        $watermark = $this->readWatermark($organizationId);

        return max($saleMax, $watermark);
    }

    protected function readWatermark(int $organizationId): int
    {
        if (! $this->watermarkTableReady()) {
            return 0;
        }

        return (int) (DB::table('sales_order_num_watermarks')
            ->where('organization_id', $organizationId)
            ->value('watermark') ?? 0);
    }

    protected function lockWatermarkRow(int $organizationId): void
    {
        if (! $this->watermarkTableReady()) {
            return;
        }

        $exists = DB::table('sales_order_num_watermarks')
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->exists();

        if (! $exists) {
            DB::table('sales_order_num_watermarks')->insert([
                'organization_id' => $organizationId,
                'watermark' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('sales_order_num_watermarks')
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();
        }
    }

    protected function writeWatermark(int $organizationId, int $watermark): void
    {
        if (! $this->watermarkTableReady()) {
            return;
        }

        $current = $this->readWatermark($organizationId);
        if ($watermark <= $current) {
            return;
        }

        DB::table('sales_order_num_watermarks')->updateOrInsert(
            ['organization_id' => $organizationId],
            [
                'watermark' => $watermark,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    protected function watermarkTableReady(): bool
    {
        return Schema::hasTable('sales_order_num_watermarks');
    }
}
