<?php

use App\Services\Inventory\StockCostCalculation;
use App\Services\Sales\CentrixSalesScope;
use App\Support\SalePaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales by User:
 * - Unpaid = remaining balance on unpaid AND partially paid orders (Gross − Collected).
 * - Collected = amount paid on fully paid AND partially paid orders.
 * - Add order counts for unpaid (balance due) and collected (any tender).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales') || ! Schema::hasTable('sale_items')) {
            return;
        }

        $this->rebuildView();
    }

    public function down(): void
    {
        // Previous definition: 2026_09_22_120000_align_sales_by_user_unpaid_with_orders_queue
    }

    protected function rebuildView(): void
    {
        $legacy = CentrixSalesScope::legacyExcludeSql('s');
        $statuses = CentrixSalesScope::reportPipelineStatusSql('s.status');
        $saleDate = CentrixSalesScope::reportSaleDateSql('s');
        $fullyPaid = SalePaymentStatus::isPaidSql('s.');
        $eps = SalePaymentStatus::EPSILON;
        $paidAgainst = 'LEAST(GREATEST(COALESCE(s.amount_paid, 0), 0), GREATEST(COALESCE(s.order_total, 0), 0))';
        $outstanding = 'GREATEST(COALESCE(s.order_total, 0) - COALESCE(s.amount_paid, 0), 0)';
        $hasBalance = "({$outstanding}) > {$eps}";
        $hasCollection = "COALESCE(s.amount_paid, 0) > {$eps}";
        $salesperson = "COALESCE(NULLIF(TRIM(u.full_name), ''), NULLIF(TRIM(u.username), ''), 'Unassigned')";

        $lineCogs = StockCostCalculation::costValueSqlExpression(
            'si.quantity',
            'COALESCE(p.last_cost_price, 0)',
            'uom',
        );

        DB::statement('DROP VIEW IF EXISTS v_sales_by_user');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_user AS
SELECT
    sales_agg.organization_id,
    sales_agg.sale_date,
    sales_agg.branch_id,
    sales_agg.cashier_id,
    sales_agg.salesperson,
    sales_agg.channel,
    sales_agg.order_count,
    sales_agg.collected_order_count,
    sales_agg.unpaid_order_count,
    sales_agg.gross_sales,
    sales_agg.total_vat,
    sales_agg.net_sales,
    sales_agg.amount_collected,
    sales_agg.fully_paid_sales,
    sales_agg.unpaid_sales,
    sales_agg.outstanding_balance,
    COALESCE(cogs.total_cost, 0) AS cogs,
    sales_agg.gross_sales - COALESCE(cogs.total_cost, 0) AS gross_profit
FROM (
    SELECT
        s.organization_id,
        {$saleDate} AS sale_date,
        s.branch_id,
        s.cashier_id,
        {$salesperson} AS salesperson,
        s.channel,
        COUNT(DISTINCT s.id) AS order_count,
        COUNT(DISTINCT CASE WHEN {$hasCollection} THEN s.id END) AS collected_order_count,
        COUNT(DISTINCT CASE WHEN {$hasBalance} THEN s.id END) AS unpaid_order_count,
        SUM(s.order_total) AS gross_sales,
        SUM(s.total_vat) AS total_vat,
        SUM(s.order_total - s.total_vat) AS net_sales,
        SUM({$paidAgainst}) AS amount_collected,
        SUM(CASE WHEN {$fullyPaid} THEN s.order_total ELSE 0 END) AS fully_paid_sales,
        SUM({$outstanding}) AS unpaid_sales,
        SUM({$outstanding}) AS outstanding_balance
    FROM sales s
    LEFT JOIN users u ON s.cashier_id = u.id
    WHERE {$statuses}
      AND s.archived = 0
      AND {$legacy}
    GROUP BY s.organization_id, {$saleDate}, s.branch_id, s.cashier_id, {$salesperson}, s.channel
) sales_agg
LEFT JOIN (
    SELECT
        s.organization_id,
        {$saleDate} AS sale_date,
        s.branch_id,
        s.cashier_id,
        s.channel,
        SUM({$lineCogs}) AS total_cost
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    LEFT JOIN products p
        ON p.product_code = si.product_code
       AND p.organization_id = s.organization_id
    LEFT JOIN uoms uom ON uom.id = p.unit_id
    WHERE {$statuses}
      AND s.archived = 0
      AND {$legacy}
    GROUP BY s.organization_id, {$saleDate}, s.branch_id, s.cashier_id, s.channel
) cogs ON cogs.organization_id = sales_agg.organization_id
    AND cogs.sale_date <=> sales_agg.sale_date
    AND cogs.branch_id <=> sales_agg.branch_id
    AND cogs.cashier_id <=> sales_agg.cashier_id
    AND cogs.channel <=> sales_agg.channel
SQL);
    }
};
