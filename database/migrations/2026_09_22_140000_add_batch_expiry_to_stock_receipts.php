<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_receipts')) {
            Schema::table('stock_receipts', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_receipts', 'batch_no')) {
                    $table->string('batch_no', 100)->nullable()->after('invoice_number');
                }
                if (! Schema::hasColumn('stock_receipts', 'expiry_date')) {
                    $table->date('expiry_date')->nullable()->after('batch_no');
                }
            });

            if (
                Schema::hasColumn('stock_receipts', 'batch_no')
                && ! $this->indexExists('stock_receipts', 'stock_receipts_org_batch_no_idx')
            ) {
                Schema::table('stock_receipts', function (Blueprint $table) {
                    $table->index(['organization_id', 'batch_no'], 'stock_receipts_org_batch_no_idx');
                });
            }
        }

        DB::statement('DROP VIEW IF EXISTS v_stock_receipts_detail');
        DB::statement(<<<'SQL'
CREATE VIEW v_stock_receipts_detail AS
SELECT
    DATE(sr.created_at) AS receipt_date,
    sr.branch_id,
    sr.organization_id,
    sr.product_code,
    p.product_name,
    sr.units_received,
    sr.stock_location,
    sr.cost_price,
    (sr.units_received * COALESCE(sr.cost_price, 0)) AS line_cost,
    sr.invoice_number,
    sr.batch_no,
    sr.expiry_date,
    u.username AS received_by
FROM stock_receipts sr
JOIN products p ON sr.product_code = p.product_code AND p.organization_id = sr.organization_id
JOIN users u ON sr.received_by = u.id
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_stock_receipts_detail');
        DB::statement(<<<'SQL'
CREATE VIEW v_stock_receipts_detail AS
SELECT
    DATE(sr.created_at) AS receipt_date,
    sr.branch_id,
    sr.organization_id,
    sr.product_code,
    p.product_name,
    sr.units_received,
    sr.stock_location,
    sr.cost_price,
    (sr.units_received * COALESCE(sr.cost_price, 0)) AS line_cost,
    sr.invoice_number,
    u.username AS received_by
FROM stock_receipts sr
JOIN products p ON sr.product_code = p.product_code
JOIN users u ON sr.received_by = u.id
SQL);

        if (! Schema::hasTable('stock_receipts')) {
            return;
        }

        if ($this->indexExists('stock_receipts', 'stock_receipts_org_batch_no_idx')) {
            Schema::table('stock_receipts', function (Blueprint $table) {
                $table->dropIndex('stock_receipts_org_batch_no_idx');
            });
        }

        Schema::table('stock_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('stock_receipts', 'expiry_date')) {
                $table->dropColumn('expiry_date');
            }
            if (Schema::hasColumn('stock_receipts', 'batch_no')) {
                $table->dropColumn('batch_no');
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1',
            [$database, $table, $index],
        );

        return $row !== null;
    }
};
