<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Till X / Z / EOD reports call TillReportMetrics::applyDebtorCollectionFilter
 * (app/Services/Pos/TillReportMetrics.php:87), producing:
 *
 *   SELECT SUM(sp.amount)
 *   FROM sale_payments sp
 *   JOIN sales s ON s.id = sp.sale_id
 *   WHERE sp.float_session_id = ?
 *     AND (s.is_credit_sale = 1
 *          OR s.float_session_id IS NULL
 *          OR s.float_session_id != sp.float_session_id);
 *
 * Observed via slow log on 2026-11-07: Rows_examined 43,697 for Rows_sent 1,
 * ~10ms per call, firing on every open-till poll (x-report).
 *
 * Root cause:
 *   - EXPLAIN on prod showed the `sp` scan type was ALL — no index served
 *     `WHERE sp.float_session_id = ?`.
 *   - The single-column `float_session_id` index created by
 *     2026_06_20_000001_sale_payments_float_session.php was not present on
 *     prod (SHOW INDEX FROM sale_payments on 2026-11-07 returned only
 *     PRIMARY, payment_method_id, idx_sale_id). The migrations table shows
 *     batch 4 did run, so the index was dropped after the fact or the
 *     Schema::hasColumn gate in the original migration short-circuited the
 *     $table->index(...) call because the column already existed.
 *
 * Fix: covering composite index (float_session_id, sale_id, amount).
 *   - float_session_id (leading): turns the WHERE into a 1-session range scan.
 *   - sale_id (middle): carries the join key so the `s` eq_ref lookup can
 *     proceed without reading sp's clustered row.
 *   - amount (trailing): lets SUM(sp.amount) be answered from the index
 *     (Extra: "Using index"), no heap reads at all.
 *
 * Verified on prod before shipping (float_session_id=95, 15 matching rows):
 *   - Before: type=ALL, rows=43511, Extra=Using where, ~10ms
 *   - After:  type=ref, rows=15,    Extra=Using index,  0.27–0.73ms
 *
 * The old single-column `float_session_id` index would also serve the WHERE
 * but not the join or the SUM, so the composite replaces it cleanly. The
 * down() path drops the covering index only; it does not restore the old
 * single-column index because the composite is a strict superset.
 *
 * Also applied by hand against production on 2026-11-07 ahead of this
 * migration landing, so `php artisan migrate --force` on the next deploy
 * must be a no-op there. The idempotent indexExists() guard below handles
 * that case — do not drop the prod index before the migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sale_payments')) {
            return;
        }

        if (! $this->indexExists('sale_payments', 'idx_sp_float_sale_amount')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $table->index(
                    ['float_session_id', 'sale_id', 'amount'],
                    'idx_sp_float_sale_amount',
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sale_payments')) {
            return;
        }

        if ($this->indexExists('sale_payments', 'idx_sp_float_sale_amount')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $table->dropIndex('idx_sp_float_sale_amount');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return (bool) $connection->selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1',
            [$database, $table, $index],
        );
    }
};
