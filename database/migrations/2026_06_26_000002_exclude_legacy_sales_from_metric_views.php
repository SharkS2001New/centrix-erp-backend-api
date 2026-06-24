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

        DB::statement('DROP VIEW IF EXISTS v_eod_cashier_summary');
        DB::statement(<<<SQL
CREATE VIEW v_eod_cashier_summary AS
SELECT
    DATE(s.created_at) AS sale_date,
    s.branch_id,
    b.branch_name,
    s.cashier_id,
    u.username AS cashier,
    COUNT(DISTINCT s.id) AS total_transactions,
    SUM(s.order_total) AS gross_sales,
    SUM(s.total_vat) AS total_vat,
    SUM(s.cash) AS cash_collected,
    SUM(s.mpesa_amount) AS mpesa_collected,
    SUM(s.equity_amount) AS equity_collected,
    SUM(s.kcb_amount) AS kcb_collected,
    SUM(s.order_total) - SUM(s.total_vat) AS net_sales,
    tfs.working_amount AS opening_float,
    tfs.float_breakdown AS float_breakdown_json
FROM sales s
JOIN branches b ON s.branch_id = b.id
JOIN users u ON s.cashier_id = u.id
LEFT JOIN till_float_sessions tfs ON s.float_session_id = tfs.id
WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
GROUP BY DATE(s.created_at), s.branch_id, s.cashier_id, tfs.id
SQL);

        DB::statement('DROP VIEW IF EXISTS v_sales_by_product');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_product AS
SELECT
    si.product_code,
    p.product_name,
    DATE(s.created_at) AS sale_date,
    s.branch_id,
    s.channel,
    SUM(si.quantity) AS qty_sold,
    si.uom AS sell_uom,
    SUM(si.amount) AS total_revenue,
    SUM(si.product_vat) AS total_vat,
    SUM(si.discount_given) AS total_discount
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN products p ON si.product_code = p.product_code
WHERE s.status = 'completed' AND {$legacy}
GROUP BY si.product_code, DATE(s.created_at), s.branch_id, s.channel, si.uom
SQL);

        DB::statement('DROP VIEW IF EXISTS v_sales_by_user');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_user AS
SELECT
    DATE(s.completed_at) AS sale_date,
    s.branch_id,
    s.cashier_id,
    u.full_name AS salesperson,
    s.channel,
    COUNT(DISTINCT s.id) AS order_count,
    SUM(s.order_total) AS gross_sales,
    SUM(s.amount_paid) AS amount_collected
FROM sales s
JOIN users u ON s.cashier_id = u.id
WHERE s.status = 'completed' AND {$legacy}
GROUP BY DATE(s.completed_at), s.branch_id, s.cashier_id, s.channel
SQL);

        DB::statement('DROP VIEW IF EXISTS v_daily_sales');
        DB::statement(<<<SQL
CREATE VIEW v_daily_sales AS
SELECT
    DATE(s.completed_at) AS sale_day,
    s.branch_id,
    b.branch_name,
    s.channel,
    COUNT(*) AS orders,
    SUM(s.order_total) AS gross,
    SUM(s.total_vat) AS vat,
    SUM(s.order_total - s.total_vat) AS net
FROM sales s
JOIN branches b ON s.branch_id = b.id
WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
GROUP BY DATE(s.completed_at), s.branch_id, s.channel
SQL);

        DB::statement('DROP VIEW IF EXISTS v_sales_by_channel');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_channel AS
SELECT
    DATE(s.completed_at) AS sale_date,
    s.branch_id,
    b.branch_name,
    s.channel,
    s.payment_status,
    COUNT(*) AS order_count,
    SUM(s.order_total) AS gross_sales,
    SUM(s.amount_paid) AS collected,
    SUM(s.total_vat) AS total_vat,
    SUM(s.order_total - s.total_vat) AS net_sales,
    SUM(CASE WHEN s.is_credit_sale = 1 THEN s.order_total ELSE 0 END) AS credit_sales
FROM sales s
JOIN branches b ON s.branch_id = b.id
WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
GROUP BY DATE(s.completed_at), s.branch_id, s.channel, s.payment_status
SQL);

        DB::statement('DROP VIEW IF EXISTS v_sales_by_customer');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_customer AS
SELECT
    c.customer_num,
    c.customer_name,
    c.phone_number,
    r.route_name,
    COUNT(DISTINCT s.id) AS total_orders,
    SUM(s.order_total) AS total_purchased,
    COALESCE(SUM(ci.invoice_total),0) AS total_invoiced,
    COALESCE(SUM(ci.amount_paid),0) AS total_paid,
    COALESCE(SUM(ci.balance_due),0) AS total_outstanding,
    c.current_balance AS ar_balance
FROM customers c
LEFT JOIN sales s ON s.customer_num = c.customer_num AND s.status='completed' AND {$legacy}
LEFT JOIN routes r ON c.route_id = r.id
LEFT JOIN customer_invoices ci ON ci.customer_num = c.customer_num AND ci.deleted_at IS NULL
WHERE c.deleted_at IS NULL
GROUP BY c.customer_num
SQL);
    }

    public function down(): void
    {
        // Views are restored by re-running prior migrations or schema deploy; no-op.
    }
};
