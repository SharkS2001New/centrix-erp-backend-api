<?php

use App\Services\Sales\CentrixSalesScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales by User: add fully_paid_sales so cashiers can reconcile with till ORDTTL.
 * Gross still includes unpaid/credit pipeline; Collected is amount_paid on those orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        $legacy = CentrixSalesScope::legacyExcludeSql('s');
        $statuses = CentrixSalesScope::reportPipelineStatusSql('s.status');
        $saleDate = CentrixSalesScope::reportSaleDateSql('s');
        // Match TillReportMetrics::collectedSalesSql (fully paid, ε = 0.009).
        $fullyPaid = 'COALESCE(s.amount_paid, 0) > 0.009'
            .' AND COALESCE(s.amount_paid, 0) + 0.009 >= COALESCE(s.order_total, 0)';

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
    SUM(s.amount_paid) AS amount_collected,
    SUM(CASE WHEN {$fullyPaid} THEN s.order_total ELSE 0 END) AS fully_paid_sales
FROM sales s
JOIN users u ON s.cashier_id = u.id
WHERE {$statuses}
  AND s.archived = 0
  AND s.cashier_id IS NOT NULL
  AND {$legacy}
GROUP BY s.organization_id, {$saleDate}, s.branch_id, s.cashier_id, u.full_name, s.channel
SQL);
    }

    public function down(): void
    {
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
    }
};
