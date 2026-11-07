<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FinalizePendingSaleStockCommand scans the entire sales table to find
 * rows where fulfillment_meta->pending_stock_deduct is truthy. Observed
 * via the MySQL slow log: Query_time 0.7s, Rows_examined 43,096,
 * Rows_sent 0 on a growing table.
 *
 * Add a VIRTUAL INVISIBLE generated column that captures the same truthy
 * check the Laravel query expresses (true | 1 | "true"), plus a composite
 * index matching the query's WHERE shape.
 *
 * VIRTUAL INVISIBLE is deliberate:
 *   - VIRTUAL avoids MySQL 8.4's ALGORITHM=COPY table rebuild that would
 *     block concurrent sales writes. The secondary index below still
 *     materialises the value on disk, so query performance is unaffected.
 *   - INVISIBLE keeps the column out of SELECT * and out of
 *     Model::replicate()'s attribute copy, so no application code (API
 *     serialisers, tests, generic model duplication) sees it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if (! Schema::hasColumn('sales', 'pending_stock_deduct')) {
            DB::statement("
                ALTER TABLE sales
                ADD COLUMN pending_stock_deduct TINYINT(1)
                GENERATED ALWAYS AS (
                    CASE
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(fulfillment_meta, '$.\"pending_stock_deduct\"'))
                             IN ('1', 'true') THEN 1
                        ELSE 0
                    END
                ) VIRTUAL INVISIBLE
            ");
        }

        if (! $this->indexExists('sales', 'idx_sales_pending_stock_deduct')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->index(
                    ['stock_balanced', 'pending_stock_deduct', 'deleted_at'],
                    'idx_sales_pending_stock_deduct',
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if ($this->indexExists('sales', 'idx_sales_pending_stock_deduct')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex('idx_sales_pending_stock_deduct');
            });
        }

        if (Schema::hasColumn('sales', 'pending_stock_deduct')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('pending_stock_deduct');
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
