<?php

namespace Tests\Unit;

use App\Support\DeadlockSafe;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeadlockSafeTest extends TestCase
{
    public function test_detects_mysql_deadlock_and_lock_wait(): void
    {
        $deadlock = new QueryException(
            'mysql',
            'update `sales` set `payment_status` = ?',
            [],
            new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
        );

        $lockWait = new QueryException(
            'mysql',
            'update `sales` set `status` = ?',
            [],
            new \PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'),
        );

        $this->assertTrue(DeadlockSafe::isRetryableLockFailure($deadlock));
        $this->assertTrue(DeadlockSafe::isRetryableLockFailure($lockWait));
        $this->assertFalse(DeadlockSafe::isRetryableLockFailure(new \RuntimeException('unrelated')));
    }

    public function test_transaction_retries_until_success(): void
    {
        $attempts = 0;

        $result = DeadlockSafe::transaction(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new QueryException(
                    'mysql',
                    'update `sales` set `payment_status` = unpaid',
                    [],
                    new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
                );
            }

            return 'ok';
        }, 5);

        $this->assertSame('ok', $result);
        $this->assertSame(3, $attempts);
    }

    public function test_transaction_survives_pdo_laravel_desync_on_commit(): void
    {
        $result = DeadlockSafe::transaction(function () {
            // Simulate MySQL ending the transaction while Laravel's counter stays open
            // (implicit commit / session drop) — the failure mode behind
            // DELETE /sales/carts/{id}/lines → "There is no active transaction".
            $pdo = DB::connection()->getPdo();
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            return 'cleared';
        });

        $this->assertSame('cleared', $result);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }
}
