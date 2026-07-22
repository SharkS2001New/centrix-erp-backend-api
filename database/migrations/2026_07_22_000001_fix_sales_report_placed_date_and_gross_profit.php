<?php

use App\Services\Inventory\StockCostCalculation;
use App\Services\Sales\CentrixSalesScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1) Sales performance reports bucket by order placed date (created_at), not
 *    completed_at — so Sales by User matches the sales list "Placed date".
 * 2) Operational gross profit = gross sales − COGS (unit/selling price basis),
 *    not net-ex-VAT − COGS (which wrongly treats VAT as lost profit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'effective_sale_date')) {
            DB::statement(
                'UPDATE sales SET effective_sale_date = DATE(created_at) WHERE created_at IS NOT NULL',
            );
        }

        $this->rebuildSalesReportViews();
        $this->rebuildProfitLossSummaryView();
    }

    public function down(): void
    {
        // Prior migrations redefine views if rolled back in order.
    }

    protected function rebuildSalesReportViews(): void
    {
        $legacy = CentrixSalesScope::legacyExcludeSql('s');
        $statuses = CentrixSalesScope::reportPipelineStatusSql('s.status');
        $saleDate = CentrixSalesScope::reportSaleDateSql('s');

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
WHERE {$statuses} AND s.archived = 0 AND {$legacy}
GROUP BY s.organization_id, {$saleDate}, s.branch_id, b.branch_name, s.channel, s.payment_status
SQL);

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

        DB::statement('DROP VIEW IF EXISTS v_sales_by_product');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_product AS
SELECT
    s.organization_id,
    si.product_code,
    p.product_name,
    {$saleDate} AS sale_date,
    s.branch_id,
    s.channel,
    SUM(si.quantity) AS qty_sold,
    si.uom AS sell_uom,
    MAX(uom.full_name) AS uom_name,
    MAX(uom.conversion_factor) AS conversion_factor,
    MAX(uom.small_packaging_label) AS small_packaging_label,
    MAX(uom.middle_packaging_label) AS middle_packaging_label,
    MAX(uom.middle_factor) AS middle_factor,
    MAX(uom.uom_type) AS uom_type,
    SUM(si.amount) AS total_revenue,
    SUM(si.product_vat) AS total_vat,
    SUM(si.discount_given) AS total_discount
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN products p ON si.product_code = p.product_code AND p.organization_id = s.organization_id
JOIN uoms uom ON uom.id = p.unit_id
WHERE {$statuses} AND s.archived = 0 AND {$legacy}
GROUP BY s.organization_id, si.product_code, p.product_name, {$saleDate}, s.branch_id, s.channel, si.uom
SQL);

        DB::statement('DROP VIEW IF EXISTS v_sales_by_supplier');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_supplier AS
SELECT
    s.organization_id,
    sup.id AS supplier_id,
    COALESCE(sup.supplier_name, 'No supplier') AS supplier_name,
    COALESCE(sup.supplier_code, '') AS supplier_code,
    si.product_code,
    p.product_name,
    {$saleDate} AS sale_date,
    s.branch_id,
    s.channel,
    COUNT(DISTINCT s.id) AS order_count,
    1 AS products_sold,
    SUM(si.quantity) AS qty_sold,
    MAX(uom.full_name) AS uom_name,
    MAX(uom.conversion_factor) AS conversion_factor,
    MAX(uom.small_packaging_label) AS small_packaging_label,
    MAX(uom.middle_packaging_label) AS middle_packaging_label,
    MAX(uom.middle_factor) AS middle_factor,
    MAX(uom.uom_type) AS uom_type,
    SUM(si.amount) AS total_revenue,
    SUM(si.product_vat) AS total_vat,
    SUM(si.discount_given) AS total_discount
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN products p ON si.product_code = p.product_code AND p.organization_id = s.organization_id
LEFT JOIN suppliers sup ON p.supplier_id = sup.id AND sup.organization_id = s.organization_id
LEFT JOIN uoms uom ON uom.id = p.unit_id
WHERE {$statuses} AND s.archived = 0 AND {$legacy}
GROUP BY
    s.organization_id, sup.id, sup.supplier_name, sup.supplier_code,
    si.product_code, p.product_name, {$saleDate}, s.branch_id, s.channel
SQL);

        DB::statement('DROP VIEW IF EXISTS v_vat_collected');
        DB::statement(<<<SQL
CREATE VIEW v_vat_collected AS
SELECT
    s.organization_id,
    {$saleDate} AS sale_date,
    s.branch_id,
    b.branch_name,
    s.channel,
    SUM(s.total_vat) AS vat_collected,
    SUM(s.order_total) AS gross_sales,
    COUNT(*) AS orders
FROM sales s
JOIN branches b ON s.branch_id = b.id
WHERE {$statuses} AND s.archived = 0 AND {$legacy}
GROUP BY s.organization_id, {$saleDate}, s.branch_id, b.branch_name, s.channel
SQL);

        DB::statement('DROP VIEW IF EXISTS v_category_sales');
        DB::statement(<<<SQL
CREATE VIEW v_category_sales AS
SELECT
    s.organization_id,
    {$saleDate} AS sale_date,
    s.branch_id,
    c.id AS category_id,
    c.category_name,
    sc.id AS sub_category_id,
    sc.subcategory_name,
    si.product_code,
    p.product_name,
    SUM(si.quantity) AS qty_sold,
    MAX(uom.full_name) AS uom_name,
    MAX(uom.conversion_factor) AS conversion_factor,
    MAX(uom.small_packaging_label) AS small_packaging_label,
    MAX(uom.middle_packaging_label) AS middle_packaging_label,
    MAX(uom.middle_factor) AS middle_factor,
    MAX(uom.uom_type) AS uom_type,
    SUM(si.amount) AS revenue,
    SUM(si.product_vat) AS vat,
    SUM(si.discount_given) AS discounts
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN products p ON si.product_code = p.product_code AND p.organization_id = s.organization_id
JOIN sub_categories sc ON p.subcategory_id = sc.id
JOIN categories c ON sc.category_id = c.id
LEFT JOIN uoms uom ON uom.id = p.unit_id
WHERE {$statuses} AND s.archived = 0 AND {$legacy}
GROUP BY
    s.organization_id, {$saleDate}, s.branch_id,
    c.id, c.category_name, sc.id, sc.subcategory_name,
    si.product_code, p.product_name
SQL);

        DB::statement('DROP VIEW IF EXISTS v_discount_summary');
        DB::statement(<<<SQL
CREATE VIEW v_discount_summary AS
SELECT
    s.organization_id,
    {$saleDate} AS sale_date,
    s.branch_id,
    s.channel,
    COUNT(DISTINCT s.id) AS orders_with_discount,
    SUM(si.discount_given) AS total_discount,
    SUM(si.amount) AS net_line_sales
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
WHERE {$statuses} AND s.archived = 0 AND si.discount_given > 0 AND {$legacy}
GROUP BY s.organization_id, {$saleDate}, s.branch_id, s.channel
SQL);
    }

    protected function rebuildProfitLossSummaryView(): void
    {
        $legacy = CentrixSalesScope::legacyExcludeSql('s');
        $lineCogs = StockCostCalculation::costValueSqlExpression(
            'si.quantity',
            'COALESCE(p.last_cost_price, 0)',
            'u',
        );
        $periodExpr = CentrixSalesScope::reportSaleDateSql('s');

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
    sales_agg.gross_revenue - COALESCE(cogs.total_cost, 0) AS gross_profit,
    COALESCE(exp.total_expenses, 0) AS total_expenses,
    sales_agg.gross_revenue - COALESCE(cogs.total_cost, 0) - COALESCE(exp.total_expenses, 0) AS net_profit
FROM (
    SELECT
        {$periodExpr} AS period,
        s.branch_id,
        b.branch_name,
        SUM(s.order_total) AS gross_revenue,
        SUM(s.total_vat) AS vat_collected,
        SUM(s.order_total) - SUM(s.total_vat) AS net_revenue
    FROM sales s
    JOIN branches b ON s.branch_id = b.id
    WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
    GROUP BY {$periodExpr}, s.branch_id, b.branch_name
) sales_agg
LEFT JOIN (
    SELECT
        {$periodExpr} AS cost_date,
        s.branch_id,
        SUM({$lineCogs}) AS total_cost
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    LEFT JOIN products p
        ON p.product_code = si.product_code
       AND p.organization_id = s.organization_id
    LEFT JOIN uoms u ON u.id = p.unit_id
    WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
    GROUP BY {$periodExpr}, s.branch_id
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
