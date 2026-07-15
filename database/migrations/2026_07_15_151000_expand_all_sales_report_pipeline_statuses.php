<?php

use App\Services\Sales\CentrixSalesScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expand all core sales-performance report views beyond completed-only
 * so booked / unpaid / processed (etc.) orders are visible for tracing.
 */
return new class extends Migration
{
    public function up(): void
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

        DB::statement('DROP VIEW IF EXISTS v_sales_by_customer');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_customer AS
SELECT
    c.organization_id,
    c.customer_num,
    c.customer_name,
    c.phone_number,
    r.route_name,
    COUNT(DISTINCT s.id) AS total_orders,
    SUM(s.order_total) AS total_purchased,
    COALESCE(SUM(ci.invoice_total), 0) AS total_invoiced,
    COALESCE(SUM(ci.amount_paid), 0) AS total_paid,
    COALESCE(SUM(ci.balance_due), 0) AS total_outstanding,
    c.current_balance AS ar_balance
FROM customers c
LEFT JOIN sales s ON s.customer_num = c.customer_num
    AND s.organization_id = c.organization_id
    AND {$statuses}
    AND s.archived = 0
    AND {$legacy}
LEFT JOIN routes r ON c.route_id = r.id
LEFT JOIN customer_invoices ci ON ci.customer_num = c.customer_num
    AND ci.organization_id = c.organization_id
    AND ci.deleted_at IS NULL
WHERE c.deleted_at IS NULL
GROUP BY c.organization_id, c.customer_num, c.customer_name, c.phone_number, r.route_name, c.current_balance
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

        DB::statement('DROP VIEW IF EXISTS v_payment_collection');
        DB::statement(<<<SQL
CREATE VIEW v_payment_collection AS
SELECT
    s.organization_id,
    DATE(sp.paid_at) AS payment_date,
    s.branch_id,
    s.channel,
    pm.method_code,
    pm.method_name,
    COUNT(*) AS payment_count,
    SUM(sp.amount) AS total_collected
FROM sale_payments sp
JOIN sales s ON sp.sale_id = s.id
JOIN payment_methods pm ON sp.payment_method_id = pm.id
WHERE {$statuses} AND s.archived = 0 AND {$legacy}
GROUP BY s.organization_id, DATE(sp.paid_at), s.branch_id, s.channel, pm.method_code, pm.method_name
SQL);
    }

    public function down(): void
    {
        // Prior sales-report migrations redefine completed-only views if rolled back.
    }
};
