<?php

namespace App\Services\Sales;

use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS thermal ticket numbers (Cash Sales #).
 *
 * - With an open till float session: sequence is per float session (starts at 1
 *   after Z/close even on the same calendar day).
 * - Without a float session: sequence is per cashier per calendar day (legacy).
 *
 * Independent of the org-wide {@see OrderNumberAllocator} (S00xx).
 */
class PosDailyOrderNumberAllocator
{
    /** Max POS thermal tickets a single reserve request may claim (legacy; unused for sequencing). */
    public const MAX_RESERVE_BLOCK = 50;

    /**
     * Next POS ticket preview for cart/UI — no locks / no watermark write.
     *
     * @return array{pos_order_num: int, pos_order_date: string}|null
     */
    public function peekNextForCashier(
        int $organizationId,
        int $cashierId,
        ?string $businessDate = null,
        ?int $floatSessionId = null,
    ): ?array {
        if (! Schema::hasColumn('sales', 'pos_order_num')) {
            return null;
        }

        $date = $this->normalizeDate($businessDate) ?? now()->toDateString();
        $sessionId = $this->normalizeFloatSessionId($floatSessionId);
        $next = $this->saleMaxForScope($organizationId, $cashierId, $date, $sessionId, locked: false) + 1;

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
        ?int $floatSessionId = null,
    ): ?array {
        if (! Schema::hasColumn('sales', 'pos_order_num')) {
            return null;
        }

        $date = $this->normalizeDate($businessDate) ?? now()->toDateString();
        $sessionId = $this->normalizeFloatSessionId($floatSessionId);

        return $this->withScopeLock($organizationId, $cashierId, $date, $sessionId, function () use (
            $organizationId,
            $cashierId,
            $date,
            $sessionId,
        ): array {
            // Include cancelled sales in the max — cancelled Cash Sales #s are consumed
            // (274 cancelled → next is 275), never reused within the same scope.
            $next = $this->saleMaxForScope($organizationId, $cashierId, $date, $sessionId, locked: true) + 1;
            $next = $this->nextFreeTicketForScope($organizationId, $cashierId, $date, $sessionId, $next);
            if ($sessionId === null) {
                $this->writeWatermark($organizationId, $cashierId, $date, $next);
            }

            return [
                'pos_order_num' => $next,
                'pos_order_date' => $date,
            ];
        });
    }

    /**
     * Preview-only helpers for offline UI. Does NOT reserve or advance Cash Sales #.
     *
     * @return array{pos_order_date: string, start: int, end: int, tickets: list<array{pos_order_num: int, pos_order_date: string}>}
     */
    public function reserveBlockForCashier(
        int $organizationId,
        int $cashierId,
        int $count,
        ?string $businessDate = null,
        ?int $floatSessionId = null,
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

        $peek = $this->peekNextForCashier($organizationId, $cashierId, $date, $floatSessionId);
        $next = (int) ($peek['pos_order_num'] ?? 1);

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
        ?int $floatSessionId = null,
    ): bool {
        return $this->claimPrintedTicketForCheckout(
            $organizationId,
            $cashierId,
            $posOrderNum,
            $businessDate,
            $floatSessionId,
        );
    }

    /**
     * Claim a free printed Cash Sales # for this cashier/day (or float session).
     */
    public function claimPrintedTicketForCheckout(
        int $organizationId,
        int $cashierId,
        int $posOrderNum,
        string $businessDate,
        ?int $floatSessionId = null,
    ): bool {
        if (! Schema::hasColumn('sales', 'pos_order_num') || $posOrderNum <= 0) {
            return false;
        }

        $date = $this->normalizeDate($businessDate);
        if ($date === null) {
            return false;
        }
        $sessionId = $this->normalizeFloatSessionId($floatSessionId);

        return $this->withScopeLock($organizationId, $cashierId, $date, $sessionId, function () use (
            $organizationId,
            $cashierId,
            $date,
            $sessionId,
            $posOrderNum,
        ): bool {
            $this->releaseParkedTicketHolders(
                $organizationId,
                $cashierId,
                $date,
                $posOrderNum,
                $sessionId,
            );

            $taken = $this->ticketIsConsumed(
                $organizationId,
                $cashierId,
                $date,
                $sessionId,
                $posOrderNum,
                locked: true,
            );

            if ($taken) {
                return false;
            }

            if ($sessionId === null) {
                $this->writeWatermark($organizationId, $cashierId, $date, $posOrderNum);
            }

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
     * Clear Cash Sales # from held/draft parks only. Cancelled tickets stay assigned
     * so the number is never reused within the same scope.
     */
    public function releaseParkedTicketHolders(
        int $organizationId,
        int $cashierId,
        string $businessDate,
        int $posOrderNum,
        ?int $floatSessionId = null,
    ): int {
        if (! Schema::hasColumn('sales', 'pos_order_num') || $posOrderNum <= 0) {
            return 0;
        }

        $date = $this->normalizeDate($businessDate);
        if ($date === null) {
            return 0;
        }
        $sessionId = $this->normalizeFloatSessionId($floatSessionId);

        $query = Sale::query()
            ->where('organization_id', $organizationId)
            ->where('pos_order_num', $posOrderNum)
            ->whereIn('status', ['held', 'draft']);

        $this->applyScopeFilters($query, $cashierId, $date, $sessionId);

        return $query->update([
            'pos_order_num' => null,
            'pos_order_date' => null,
        ]);
    }

    /** @deprecated use releaseParkedTicketHolders */
    public function releaseInactiveTicketHolders(
        int $organizationId,
        int $cashierId,
        string $businessDate,
        int $posOrderNum,
        ?int $floatSessionId = null,
    ): int {
        return $this->releaseParkedTicketHolders(
            $organizationId,
            $cashierId,
            $businessDate,
            $posOrderNum,
            $floatSessionId,
        );
    }

    /**
     * True when a live or cancelled sale already owns this Cash Sales # in scope.
     */
    protected function ticketIsConsumed(
        int $organizationId,
        int $cashierId,
        string $date,
        ?int $floatSessionId,
        int $posOrderNum,
        bool $locked = false,
    ): bool {
        $query = Sale::query()
            ->where('organization_id', $organizationId)
            ->where('pos_order_num', $posOrderNum)
            ->whereNotIn('status', ['held', 'draft']);

        $this->applyScopeFilters($query, $cashierId, $date, $floatSessionId);

        if ($locked) {
            $query->lockForUpdate();
        }

        return $query->exists();
    }

    /**
     * Next ticket free of live + cancelled holders within the same scope.
     */
    protected function nextFreeTicketForScope(
        int $organizationId,
        int $cashierId,
        string $date,
        ?int $floatSessionId,
        int $start,
    ): int {
        $candidate = max(1, $start);
        for ($i = 0; $i < 50; $i++) {
            $this->releaseParkedTicketHolders(
                $organizationId,
                $cashierId,
                $date,
                $candidate,
                $floatSessionId,
            );
            if (! $this->ticketIsConsumed(
                $organizationId,
                $cashierId,
                $date,
                $floatSessionId,
                $candidate,
                locked: true,
            )) {
                return $candidate;
            }
            $candidate++;
        }

        return $candidate;
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withScopeLock(
        int $organizationId,
        int $cashierId,
        string $date,
        ?int $floatSessionId,
        callable $callback,
    ): mixed {
        $lockKey = $floatSessionId !== null
            ? sprintf('pos_session_order_num:%d:%d', $organizationId, $floatSessionId)
            : sprintf('pos_daily_order_num:%d:%d:%s', $organizationId, $cashierId, $date);

        $run = function () use ($lockKey, $callback) {
            $lock = DB::selectOne('SELECT GET_LOCK(?, 15) AS acquired', [$lockKey]);
            if (! $lock || (int) ($lock->acquired ?? 0) !== 1) {
                throw new \InvalidArgumentException(
                    'Could not allocate a Cash Sales #. Please try sync again.',
                );
            }

            try {
                return $callback();
            } finally {
                DB::select('SELECT RELEASE_LOCK(?)', [$lockKey]);
            }
        };

        if (DB::transactionLevel() > 0) {
            return $run();
        }

        return DB::transaction($run);
    }

    /** @deprecated use withScopeLock */
    protected function withCashierDayLock(
        int $organizationId,
        int $cashierId,
        string $date,
        callable $callback,
    ): mixed {
        return $this->withScopeLock($organizationId, $cashierId, $date, null, $callback);
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

    protected function normalizeFloatSessionId(?int $floatSessionId): ?int
    {
        $id = (int) ($floatSessionId ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Sale>  $query
     */
    protected function applyScopeFilters($query, int $cashierId, string $date, ?int $floatSessionId): void
    {
        if ($floatSessionId !== null) {
            $query->where('float_session_id', $floatSessionId);

            return;
        }

        $query->where('cashier_id', $cashierId)
            ->whereDate('pos_order_date', $date)
            ->where(function ($inner) {
                $inner->whereNull('float_session_id')
                    ->orWhere('float_session_id', 0);
            });
    }

    protected function saleMaxForScope(
        int $organizationId,
        int $cashierId,
        string $date,
        ?int $floatSessionId,
        bool $locked = false,
    ): int {
        $query = Sale::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('pos_order_num')
            // Parks must not advance Cash Sales #. Cancelled sales DO — the number
            // was issued and must be skipped within this session/day scope.
            ->whereNotIn('status', ['held', 'draft']);

        $this->applyScopeFilters($query, $cashierId, $date, $floatSessionId);

        if ($locked) {
            $query->lockForUpdate();
        }

        return (int) ($query->max('pos_order_num') ?? 0);
    }

    /** @deprecated use saleMaxForScope */
    protected function saleMaxForCashierDay(
        int $organizationId,
        int $cashierId,
        string $date,
        bool $locked = false,
    ): int {
        return $this->saleMaxForScope($organizationId, $cashierId, $date, null, $locked);
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
