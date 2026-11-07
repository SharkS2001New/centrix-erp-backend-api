<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cluster-wide polling query `SELECT * FROM background_tasks
 * WHERE status IN ('pending','running')` cannot use the existing
 * (organization_id, type, status) composite index because its leading
 * column is unmatched. Observed via slow log: Rows_examined 928 for
 * Rows_sent 0, growing with the table.
 *
 * Add a status-only index. BackgroundTaskService's query has no date
 * predicate or ordering, so a trailing created_at column would be dead
 * weight. The low-cardinality scan is accepted here: the working set of
 * pending/running tasks is small, and MySQL's cost-based optimiser can
 * still fall back to a table scan when the fraction of in-flight rows
 * dominates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('background_tasks')) {
            return;
        }

        if (! $this->indexExists('background_tasks', 'idx_background_tasks_status')) {
            Schema::table('background_tasks', function (Blueprint $table) {
                $table->index('status', 'idx_background_tasks_status');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('background_tasks')) {
            return;
        }

        if ($this->indexExists('background_tasks', 'idx_background_tasks_status')) {
            Schema::table('background_tasks', function (Blueprint $table) {
                $table->dropIndex('idx_background_tasks_status');
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
