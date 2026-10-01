<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Retry MySQL 1213 / 1205 lock failures the same way inventory already does.
 *
 * Concurrent POS / mobile / backoffice checkouts can deadlock on sales + stock
 * even with consistent lock order — the durable fix is automatic restart.
 */
final class DeadlockSafe
{
    public const DEFAULT_ATTEMPTS = 5;

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function transaction(callable $callback, int $attempts = self::DEFAULT_ATTEMPTS): mixed
    {
        $attempts = max(1, $attempts);

        // Nested transactions cannot safely restart — caller already holds locks.
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        return DB::transaction($callback, $attempts);
    }

    public static function isRetryableLockFailure(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, '1213')
            || str_contains($message, '1205')
            || str_contains($message, 'Deadlock found')
            || str_contains($message, 'Lock wait timeout')
            || str_contains($message, 'Serialization failure');
    }
}
