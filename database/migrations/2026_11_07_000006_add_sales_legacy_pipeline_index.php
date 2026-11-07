<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite index for the sales-by-* report query shape, exploiting the
 * VIRTUAL INVISIBLE `legacy_materialized` column added in
 * 2026_06_19_000001_add_sales_legacy_materialized_column.
 *
 * Column order chosen for the shape used by every report path:
 *   - organization_id first: tenant isolation is the common prefix on every query.
 *   - legacy_materialized second: narrows the 43,073 live rows out of 43,098
 *     total while keeping the leading key compact (1 byte).
 *   - status + archived + effective_sale_date: match the usual
 *     `status IN (...) AND archived = 0 AND effective_sale_date BETWEEN ...`
 *     filter block that follows every `legacy = 0` exclusion.
 *
 * This file is dated 2026-11-07 and runs after
 * 2026_07_05_100001_add_sales_effective_sale_date adds the trailing column.
 * The split from 2026_06_19_000001 exists because effective_sale_date isn't
 * available until that later date.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if (! Schema::hasColumn('sales', 'legacy_materialized')) {
            return;
        }

        if (! Schema::hasColumn('sales', 'effective_sale_date')) {
            return;
        }

        if (! $this->indexExists('sales', 'idx_sales_legacy_pipeline')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->index(
                    ['organization_id', 'legacy_materialized', 'status', 'archived', 'effective_sale_date'],
                    'idx_sales_legacy_pipeline',
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if ($this->indexExists('sales', 'idx_sales_legacy_pipeline')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex('idx_sales_legacy_pipeline');
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
