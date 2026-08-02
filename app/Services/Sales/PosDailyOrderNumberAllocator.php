<?php

namespace App\Services\Sales;

use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS thermal ticket numbers: start at 1 each calendar day per cashier.
 * Independent of the org-wide {@see OrderNumberAllocator} (S00xx).
 */
class PosDailyOrderNumberAllocator
{
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
            $max = (int) (Sale::query()
                ->where('organization_id', $organizationId)
                ->where('cashier_id', $cashierId)
                ->whereDate('pos_order_date', $date)
                ->whereNotNull('pos_order_num')
                ->max('pos_order_num') ?? 0);

            return [
                'pos_order_num' => $max + 1,
                'pos_order_date' => $date,
            ];
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
}
