<?php

namespace App\Services\Sales;

use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS thermal ticket numbers (Cash Sales #): start at 1 each calendar day per cashier.
 * Sequence is driven only by completed sales — never by offline org-order reserves.
 * Independent of the org-wide {@see OrderNumberAllocator} (S00xx), which may jump.
 */
class PosDailyOrderNumberAllocator
{
    /** Max POS thermal tickets a single reserve request may claim (legacy; unused for sequencing). */
    public const MAX_RESERVE_BLOCK = 50;

    /**
     * Next daily POS ticket preview for cart/UI — no locks / no watermark write.
     *
     * @return array{pos_order_num: int, pos_order_date: string}|null
     */
    public function peekNextForCashier(
        int $organizationId,
        int $cashierId,
        ?string $businessDate = null,
    ): ?array {
        if (! Schema::hasColumn('sales', 'pos_order_num')) {
            return null;
        }

        $date = $this->normalizeDate($businessDate) ?? now()->toDateString();
        $next = $this->saleMaxForCashierDay($organizationId, $cashierId, $date, locked: false) + 1;

        return [
            'pos_order_num' => $next,
            'pos_order_date' => $date,
        ];
    }

    /**
     * @return array{pos_order_num: int, pos_order_date: string}|null
     */
    public function allocateForCheckout(
        int $organizationId,
        int $cashierId,
        ?string $businessDate = null,
    ): ?array {
        if (! Schema::hasColumn('sales', 'pos_order_num')) {
            return null;
        }

        $date = $this->normalizeDate($businessDate) ?? now()->toDateString();

        return $this->withCashierDayLock($organizationId, $cashierId, $date, function () use (
            $organizationId,
            $cashierId,
            $date,
        ): array {
            $next = $this->saleMaxForCashierDay($organizationId, $cashierId, $date, locked: true) + 1;
            $this->writeWatermark($organizationId, $cashierId, $date, $next);

            return [
                'pos_order_num' => $next,
                'pos_order_date' => $date,
            ];
        });
    }

    /**
     * Preview-only helpers for offline UI. Does NOT reserve or advance Cash Sales #.
     * Org S00xx numbers are reserved separately; POS tickets are assigned at sale time
     * as saleMax+1 so each cashier stays on 1, 2, 3… with no reserve-block gaps.
     *
     * @return array{pos_order_date: string, start: int, end: int, tickets: list<array{pos_order_num: int, pos_order_date: string}>}
     */
    public function reserveBlockForCashier(
        int $organizationId,
        int $cashierId,
        int $count,
        ?string $businessDate = null,
    ): array {
        $date = $this->normalizeDate($businessDate) ?? now()->toDateString();
        if (! Schema::hasColumn('sales', 'pos_order_num')) {
            return [
                'pos_order_date' => $date,
                'start' => 0,
                'end' => 0,
                'tickets' => [],
            ];
        }

        $peek = $this->peekNextForCashier($organizationId, $cashierId, $date);
        $next = (int) ($peek['pos_order_num'] ?? 1);

        // Return empty tickets — callers must not pre-bind Cash Sales # to org slots.
        return [
            'pos_order_date' => $date,
            'start' => $next,
            'end' => $next,
            'tickets' => [],
            'next_pos_order_num' => $next,
        ];
    }

    /**
     * Claim a free Cash Sales # for this cashier/day (legacy name).
     * Prefer {@see claimPrintedTicketForCheckout}.
     */
    public function claimReservedForCheckout(
        int $organizationId,
        int $cashierId,
        int $posOrderNum,
        string $businessDate,
    ): bool {
        return $this->claimPrintedTicketForCheckout(
            $organizationId,
            $cashierId,
            $posOrderNum,
            $businessDate,
        );
    }

    /**
     * Claim a free printed Cash Sales # for this cashier/day.
     */
    public function claimPrintedTicketForCheckout(
        int $organizationId,
        int $cashierId,
        int $posOrderNum,
        string $businessDate,
    ): bool {
        if (! Schema::hasColumn('sales', 'pos_order_num') || $posOrderNum <= 0) {
            return false;
        }

        $date = $this->normalizeDate($businessDate);
        if ($date === null) {
            return false;
        }

        return $this->withCashierDayLock($organizationId, $cashierId, $date, function () use (
            $organizationId,
            $cashierId,
            $date,
            $posOrderNum,
        ): bool {
            $taken = Sale::query()
                ->where('organization_id', $organizationId)
                ->where('cashier_id', $cashierId)
                ->whereDate('pos_order_date', $date)
                ->where('pos_order_num', $posOrderNum)
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                return false;
            }

            $this->writeWatermark($organizationId, $cashierId, $date, $posOrderNum);

            return true;
        });
    }

    /**
     * Transfer a POS ticket number from a superseded sale (POS order edit).
     *
     * @return array{pos_order_num: int, pos_order_date: string}|null
     */
    public function takeFromSale(Sale $sale): ?array
    {
        if (! Schema::hasColumn('sales', 'pos_order_num')) {
            return null;
        }

        $num = $sale->pos_order_num !== null ? (int) $sale->pos_order_num : null;
        $date = $this->normalizeDate(
            $sale->pos_order_date instanceof \DateTimeInterface
                ? $sale->pos_order_date->format('Y-m-d')
                : ($sale->pos_order_date !== null ? (string) $sale->pos_order_date : null),
        );

        if ($num === null || $num <= 0 || $date === null) {
            return null;
        }

        // Free the unique key so the replacement sale can keep the same Cash Sales #.
        $sale->forceFill([
            'pos_order_num' => null,
            'pos_order_date' => null,
        ])->save();

        return [
            'pos_order_num' => $num,
            'pos_order_date' => $date,
        ];
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withCashierDayLock(
        int $organizationId,
        int $cashierId,
        string $date,
        callable $callback,
    ): mixed {
        $lockKey = sprintf('pos_daily_order_num:%d:%d:%s', $organizationId, $cashierId, $date);

        return DB::transaction(function () use ($lockKey, $callback) {
            $lock = DB::selectOne('SELECT GET_LOCK(?, 15) AS acquired', [$lockKey]);
            if (! $lock || (int) ($lock->acquired ?? 0) !== 1) {
                throw new \RuntimeException(
                    'Could not allocate a POS order number. Please try again.',
                );
            }

            try {
                return $callback();
            } finally {
                DB::select('SELECT RELEASE_LOCK(?)', [$lockKey]);
            }
        });
    }

    /**
     * Public helper: accept ISO / Y-m-d / other parseable dates → Y-m-d.
     */
    public function normalizeBusinessDate(?string $value): ?string
    {
        return $this->normalizeDate($value);
    }

    protected function normalizeDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function saleMaxForCashierDay(
        int $organizationId,
        int $cashierId,
        string $date,
        bool $locked = false,
    ): int {
        $query = Sale::query()
            ->where('organization_id', $organizationId)
            ->where('cashier_id', $cashierId)
            ->whereDate('pos_order_date', $date)
            ->whereNotNull('pos_order_num');

        if ($locked) {
            $query->lockForUpdate();
        }

        return (int) ($query->max('pos_order_num') ?? 0);
    }

    protected function readWatermark(int $organizationId, int $cashierId, string $date): int
    {
        if (! Schema::hasTable('pos_daily_order_watermarks')) {
            return 0;
        }

        return (int) (DB::table('pos_daily_order_watermarks')
            ->where('organization_id', $organizationId)
            ->where('cashier_id', $cashierId)
            ->where('pos_order_date', $date)
            ->value('watermark') ?? 0);
    }

    protected function lockWatermarkRow(int $organizationId, int $cashierId, string $date): void
    {
        if (! Schema::hasTable('pos_daily_order_watermarks')) {
            return;
        }

        $exists = DB::table('pos_daily_order_watermarks')
            ->where('organization_id', $organizationId)
            ->where('cashier_id', $cashierId)
            ->where('pos_order_date', $date)
            ->lockForUpdate()
            ->exists();

        if (! $exists) {
            DB::table('pos_daily_order_watermarks')->insert([
                'organization_id' => $organizationId,
                'cashier_id' => $cashierId,
                'pos_order_date' => $date,
                'watermark' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('pos_daily_order_watermarks')
                ->where('organization_id', $organizationId)
                ->where('cashier_id', $cashierId)
                ->where('pos_order_date', $date)
                ->lockForUpdate()
                ->first();
        }
    }

    protected function writeWatermark(
        int $organizationId,
        int $cashierId,
        string $date,
        int $watermark,
    ): void {
        if (! Schema::hasTable('pos_daily_order_watermarks')) {
            return;
        }

        $this->lockWatermarkRow($organizationId, $cashierId, $date);

        $current = $this->readWatermark($organizationId, $cashierId, $date);
        if ($watermark <= $current) {
            return;
        }

        DB::table('pos_daily_order_watermarks')->updateOrInsert(
            [
                'organization_id' => $organizationId,
                'cashier_id' => $cashierId,
                'pos_order_date' => $date,
            ],
            [
                'watermark' => $watermark,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
}
