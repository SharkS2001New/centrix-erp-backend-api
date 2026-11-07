<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add sale_payments.float_session_id plus a single-column index.
 *
 * The original version of this migration wrapped both the column add and the
 * index create inside a single `Schema::hasColumn` gate. If an environment
 * already had the column from another path but not the index (observed on
 * prod 2026-11-07 — batch 4 of this migration recorded as applied, but
 * SHOW INDEX FROM sale_payments showed only PRIMARY, payment_method_id,
 * idx_sale_id), the index silently never got created. The split gates below
 * make column and index idempotent independently.
 *
 * The covering composite idx_sp_float_sale_amount (float_session_id,
 * sale_id, amount) added in 2026_11_07_000003 is a strict superset of the
 * narrow index created here, so on envs that run both migrations in order
 * the narrow index is redundant. It is kept because dropping it from this
 * migration retroactively would change the historical schema, and the
 * cost is a few hundred KB of btree.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sale_payments', 'float_session_id')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $table->bigInteger('float_session_id')->nullable()->after('sale_id');
            });
        }

        if (! $this->indexExists('sale_payments', 'sale_payments_float_session_id_index')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $table->index('float_session_id');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('sale_payments', 'sale_payments_float_session_id_index')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $table->dropIndex(['float_session_id']);
            });
        }

        if (Schema::hasColumn('sale_payments', 'float_session_id')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $table->dropColumn('float_session_id');
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
