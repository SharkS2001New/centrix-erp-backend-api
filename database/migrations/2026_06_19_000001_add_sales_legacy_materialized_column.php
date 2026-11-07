<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds VIRTUAL INVISIBLE `sales.legacy_materialized` — a boolean projection of
 * the fulfillment_meta->legacy_import JSON flag — so CentrixSalesScope helpers
 * can emit a simple `legacy_materialized = 0` predicate instead of the
 * unindexable JSON function chain.
 *
 * The composite index that makes the column useful is added in
 * 2026_11_07_000006_add_sales_legacy_pipeline_index. The split exists because
 * the index references `effective_sale_date`, added in
 * 2026_07_05_100001_add_sales_effective_sale_date — so the column must land
 * before the earliest view migration (2026_06_20_120000) but the index must
 * land after the date column.
 *
 * ## Why this file is dated 2026_06_19 (written 2026-11-07)
 *
 * CentrixSalesScope::legacyExcludeSql() is called at CREATE VIEW time by 20
 * pre-existing migrations dated between 2026_06_20 and 2026_09_24. Once the
 * helper returns `s.legacy_materialized = 0`, those migrations on a fresh
 * `migrate:fresh` or DB re-import would fail with "Unknown column
 * legacy_materialized" unless this migration runs first. Dating the file
 * 2026_06_19 — one day before the earliest view migration — guarantees
 * Laravel runs it first. Prod, which has already applied the view migrations
 * with the old JSON predicate, picks up this migration on the next deploy as
 * a normal rollforward; the `migrations` table only cares that the row
 * doesn't exist yet, not whether its filename is in the past.
 *
 * ## Truthiness contract
 *
 * The generated column treats PHP `true`, int `1`, string `"1"`, and string
 * `"true"` as legacy (= 1); everything else (false, 0, "0", "false", null,
 * missing key, int 2, string "TRUE", arrays, objects) as live (= 0).
 * CentrixSalesScope::isLegacyImportFlag() implements the same contract in PHP
 * so reader and writer agree. See SalesLegacyMaterializedScopeTest for the
 * parity matrix proved against MySQL 8.4.
 *
 * VIRTUAL INVISIBLE mirrors 2026_11_07_000001_add_sales_pending_stock_deduct_index:
 *   - VIRTUAL avoids MySQL 8.4's ALGORITHM=COPY table rebuild (blocks
 *     concurrent sales writes). The secondary index added by the follow-up
 *     migration materializes the value on disk.
 *   - INVISIBLE keeps the column out of SELECT *, Model::replicate(), and
 *     API/JSON serializers. Application code reads the column only via
 *     explicit `where('legacy_materialized', ...)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if (! Schema::hasColumn('sales', 'legacy_materialized')) {
            DB::statement("
                ALTER TABLE sales
                ADD COLUMN legacy_materialized TINYINT(1)
                GENERATED ALWAYS AS (
                    CASE
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(fulfillment_meta, '$.\"legacy_import\"'))
                             IN ('1', 'true') THEN 1
                        ELSE 0
                    END
                ) VIRTUAL INVISIBLE
            ");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if (Schema::hasColumn('sales', 'legacy_materialized')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('legacy_materialized');
            });
        }
    }
};
