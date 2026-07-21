<?php

use App\Services\Sales\CentrixSalesScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $legacy = CentrixSalesScope::legacyExcludeSql('s');

        DB::statement('DROP VIEW IF EXISTS v_profit_loss_summary');
        DB::statement(<<<SQL
CREATE VIEW v_profit_loss_summary AS
SELECT
    sales_agg.period,
    sales_agg.branch_id,
    sales_agg.branch_name,
    sales_agg.gross_revenue,
    sales_agg.vat_collected,
    sales_agg.net_revenue,
    COALESCE(cogs.total_cost, 0) AS cogs,
    sales_agg.net_revenue - COALESCE(cogs.total_cost, 0) AS gross_profit,
    COALESCE(exp.total_expenses, 0) AS total_expenses,
    sales_agg.net_revenue - COALESCE(cogs.total_cost, 0) - COALESCE(exp.total_expenses, 0) AS net_profit
FROM (
    SELECT
        DATE(s.completed_at) AS period,
        s.branch_id,
        b.branch_name,
        SUM(s.order_total) AS gross_revenue,
        SUM(s.total_vat) AS vat_collected,
        SUM(s.order_total) - SUM(s.total_vat) AS net_revenue
    FROM sales s
    JOIN branches b ON s.branch_id = b.id
    WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
    GROUP BY DATE(s.completed_at), s.branch_id, b.branch_name
) sales_agg
LEFT JOIN (
    SELECT
        DATE(s.completed_at) AS cost_date,
        s.branch_id,
        SUM(si.quantity * COALESCE(p.last_cost_price, 0)) AS total_cost
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    LEFT JOIN products p
        ON p.product_code = si.product_code
       AND p.organization_id = s.organization_id
    WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
    GROUP BY DATE(s.completed_at), s.branch_id
) cogs ON sales_agg.period = cogs.cost_date AND sales_agg.branch_id = cogs.branch_id
LEFT JOIN (
    SELECT
        expense_date,
        branch_id,
        SUM(expense_amount) AS total_expenses
    FROM expenses
    WHERE deleted_at IS NULL
    GROUP BY expense_date, branch_id
) exp ON sales_agg.period = exp.expense_date AND sales_agg.branch_id = exp.branch_id
SQL);
    }

    public function down(): void
    {
        $legacy = CentrixSalesScope::legacyExcludeSql('s');

        DB::statement('DROP VIEW IF EXISTS v_profit_loss_summary');
        DB::statement(<<<SQL
CREATE VIEW v_profit_loss_summary AS
SELECT
    sales_agg.period,
    sales_agg.branch_id,
    sales_agg.branch_name,
    sales_agg.gross_revenue,
    sales_agg.vat_collected,
    sales_agg.net_revenue,
    COALESCE(cogs.total_cost, 0) AS cogs,
    sales_agg.net_revenue - COALESCE(cogs.total_cost, 0) AS gross_profit,
    COALESCE(exp.total_expenses, 0) AS total_expenses,
    sales_agg.net_revenue - COALESCE(cogs.total_cost, 0) - COALESCE(exp.total_expenses, 0) AS net_profit
FROM (
    SELECT
        DATE(s.completed_at) AS period,
        s.branch_id,
        b.branch_name,
        SUM(s.order_total) AS gross_revenue,
        SUM(s.total_vat) AS vat_collected,
        SUM(s.order_total) - SUM(s.total_vat) AS net_revenue
    FROM sales s
    JOIN branches b ON s.branch_id = b.id
    WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
    GROUP BY DATE(s.completed_at), s.branch_id, b.branch_name
) sales_agg
LEFT JOIN (
    SELECT
        DATE(sr.created_at) AS cost_date,
        sr.branch_id,
        SUM(sr.units_received * COALESCE(sr.cost_price, 0)) AS total_cost
    FROM stock_receipts sr
    GROUP BY DATE(sr.created_at), sr.branch_id
) cogs ON sales_agg.period = cogs.cost_date AND sales_agg.branch_id = cogs.branch_id
LEFT JOIN (
    SELECT
        expense_date,
        branch_id,
        SUM(expense_amount) AS total_expenses
    FROM expenses
    WHERE deleted_at IS NULL
    GROUP BY expense_date, branch_id
) exp ON sales_agg.period = exp.expense_date AND sales_agg.branch_id = exp.branch_id
SQL);
    }
};
