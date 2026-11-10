<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Retry MySQL 1213 / 1205 lock failures the same way inventory already does.
 *
 * Concurrent POS / mobile / backoffice checkouts can deadlock on sales + stock
 * even with consistent lock order — the durable fix is automatic restart.
 *
 * Also guards against Laravel/PDO transaction-counter desync ("There is no
 * active transaction" on commit) after nested work, reconnects, or MySQL
 * implicit commits leave PDO with no transaction while Laravel still thinks
 * one is open.
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

        $last = null;
        for ($try = 1; $try <= $attempts; $try++) {
            try {
                return self::runTopLevelTransaction($callback);
            } catch (Throwable $e) {
                $last = $e;
                if ($try >= $attempts || ! self::isRetryableLockFailure($e)) {
                    throw $e;
                }
                // Brief jitter so concurrent POS cashiers don't re-collide immediately.
                usleep(25_000 * $try + random_int(0, 20_000));
            }
        }

        throw $last ?? new \RuntimeException('DeadlockSafe exhausted attempts without an exception.');
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    protected static function runTopLevelTransaction(callable $callback): mixed
    {
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $result = $callback();
        } catch (Throwable $e) {
            self::safeRollBack($connection);
            throw $e;
        }

        self::safeCommit($connection);

        return $result;
    }

    protected static function safeCommit(mixed $connection): void
    {
        while ($connection->transactionLevel() > 0) {
            $pdo = $connection->getPdo();
            if ($pdo instanceof PDO && ! $pdo->inTransaction()) {
                // Laravel counter is ahead of PDO (implicit commit / reconnect desync).
                // Reset without calling PDO::commit(), which would throw.
                self::resetTransactionLevel($connection);

                return;
            }

            $connection->commit();
        }
    }

    protected static function safeRollBack(mixed $connection): void
    {
        if ($connection->transactionLevel() <= 0) {
            return;
        }

        try {
            $connection->rollBack();
        } catch (Throwable) {
            self::resetTransactionLevel($connection);
        }
    }

    protected static function resetTransactionLevel(mixed $connection): void
    {
        while ($connection->transactionLevel() > 0) {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                // Force-clear when PDO is already idle.
                if (method_exists($connection, 'setPdo')) {
                    $connection->setPdo($connection->getPdo());
                }
                break;
            }
        }
    }

    public static function isRetryableLockFailure(Throwable $e): bool
    {
        $message = $e->getMessage();
        $sqlState = method_exists($e, 'getCode') ? (string) $e->getCode() : '';

        return $sqlState === '40001'
            || str_contains($message, '1213')
            || str_contains($message, '1205')
            || str_contains($message, 'Deadlock found')
            || str_contains($message, 'Lock wait timeout')
            || str_contains($message, 'Serialization failure');
    }
}
