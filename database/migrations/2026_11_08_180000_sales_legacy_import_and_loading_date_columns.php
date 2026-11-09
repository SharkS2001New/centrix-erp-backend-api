<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STORED generated columns so mobile dashboards / loading sheets can filter and
 * group without JSON path predicates or DATE() wraps on every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if (! Schema::hasColumn('sales', 'is_legacy_import')) {
            DB::statement("
                ALTER TABLE sales
                ADD COLUMN is_legacy_import TINYINT(1)
                    GENERATED ALWAYS AS (
                        CASE
                            WHEN fulfillment_meta IS NULL THEN 0
                            WHEN JSON_EXTRACT(fulfillment_meta, '\$.legacy_import') IS NULL THEN 0
                            WHEN JSON_EXTRACT(fulfillment_meta, '\$.legacy_import') = true THEN 1
                            WHEN JSON_UNQUOTE(JSON_EXTRACT(fulfillment_meta, '\$.legacy_import')) IN ('true', '1') THEN 1
                            ELSE 0
                        END
                    ) STORED
                    AFTER fulfillment_meta
            ");
        }

        if (! Schema::hasColumn('sales', 'loading_date')) {
            DB::statement('
                ALTER TABLE sales
                ADD COLUMN loading_date DATE
                    GENERATED ALWAYS AS (
                        COALESCE(DATE(delivery_date), DATE(created_at))
                    ) STORED
                    AFTER delivery_date
            ');
        }

        $this->ensureIndex(
            'sales',
            'sales_org_channel_loading_route_idx',
            '(organization_id, channel, loading_date, route_id)',
        );
        $this->ensureIndex(
            'sales',
            'sales_cashier_archived_legacy_created_idx',
            '(cashier_id, archived, is_legacy_import, created_at)',
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        $this->dropIndexIfExists('sales', 'sales_org_channel_loading_route_idx');
        $this->dropIndexIfExists('sales', 'sales_cashier_archived_legacy_created_idx');

        if (Schema::hasColumn('sales', 'is_legacy_import')) {
            Schema::table('sales', function ($table) {
                $table->dropColumn('is_legacy_import');
            });
        }
        if (Schema::hasColumn('sales', 'loading_date')) {
            Schema::table('sales', function ($table) {
                $table->dropColumn('loading_date');
            });
        }
    }

    protected function ensureIndex(string $table, string $name, string $columnsSql): void
    {
        $exists = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
             LIMIT 1',
            [$table, $name],
        );
        if ($exists) {
            return;
        }

        try {
            DB::statement("CREATE INDEX {$name} ON {$table} {$columnsSql}");
        } catch (\Throwable) {
            // Index may already exist under another name, or engine may reject.
        }
    }

    protected function dropIndexIfExists(string $table, string $name): void
    {
        $exists = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
             LIMIT 1',
            [$table, $name],
        );
        if (! $exists) {
            return;
        }

        try {
            DB::statement("DROP INDEX {$name} ON {$table}");
        } catch (\Throwable) {
            //
        }
    }
};
