<?php

use App\Services\Sales\CentrixSalesScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep Sales by User / daily sales views on the same calendar day as Sales Orders
 * (effective_sale_date = placed date). Fixes AI vs report drift when DATE(created_at)
 * and effective_sale_date disagreed for some rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'effective_sale_date')) {
            DB::statement(
                'UPDATE sales SET effective_sale_date = DATE(created_at) WHERE created_at IS NOT NULL'
            );
        }

        if (! Schema::hasTable('sales')) {
            return;
        }

        $legacy = CentrixSalesScope::legacyExcludeSql('s');
        $statuses = CentrixSalesScope::reportPipelineStatusSql('s.status');
        $saleDate = CentrixSalesScope::reportSaleDateSql('s');

        DB::statement('DROP VIEW IF EXISTS v_sales_by_user');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_user AS
SELECT
    s.organization_id,
    {$saleDate} AS sale_date,
    s.branch_id,
    s.cashier_id,
    u.full_name AS salesperson,
    s.channel,
    COUNT(DISTINCT s.id) AS order_count,
    SUM(s.order_total) AS gross_sales,
    SUM(s.total_vat) AS total_vat,
    SUM(s.order_total - s.total_vat) AS net_sales,
    SUM(s.amount_paid) AS amount_collected
FROM sales s
JOIN users u ON s.cashier_id = u.id
WHERE {$statuses}
  AND s.archived = 0
  AND s.cashier_id IS NOT NULL
  AND {$legacy}
GROUP BY s.organization_id, {$saleDate}, s.branch_id, s.cashier_id, u.full_name, s.channel
SQL);

        DB::statement('DROP VIEW IF EXISTS v_daily_sales');
        DB::statement(<<<SQL
CREATE VIEW v_daily_sales AS
SELECT
    s.organization_id,
    {$saleDate} AS sale_day,
    s.branch_id,
    b.branch_name,
    s.channel,
    COUNT(*) AS orders,
    SUM(s.order_total) AS gross,
    SUM(s.total_vat) AS vat,
    SUM(s.order_total - s.total_vat) AS net
FROM sales s
JOIN branches b ON s.branch_id = b.id
WHERE {$statuses} AND s.archived = 0 AND {$legacy}
GROUP BY s.organization_id, {$saleDate}, s.branch_id, b.branch_name, s.channel
SQL);

        DB::statement('DROP VIEW IF EXISTS v_sales_by_channel');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_channel AS
SELECT
    s.organization_id,
    {$saleDate} AS sale_date,
    s.branch_id,
    s.channel,
    COUNT(*) AS order_count,
    SUM(s.order_total) AS gross_sales,
    SUM(s.total_vat) AS total_vat,
    SUM(s.order_total - s.total_vat) AS net_sales,
    SUM(s.amount_paid) AS amount_collected
FROM sales s
WHERE {$statuses} AND s.archived = 0 AND {$legacy}
GROUP BY s.organization_id, {$saleDate}, s.branch_id, s.channel
SQL);
    }

    public function down(): void
    {
        // Views are rebuilt by earlier migrations if rolled back in order.
    }
};
