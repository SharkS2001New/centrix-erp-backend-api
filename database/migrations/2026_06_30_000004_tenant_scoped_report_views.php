<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected string $legacySalesFilter = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.fulfillment_meta, '$.legacy_import')), 'false') <> 'true'";

    public function up(): void
    {
        $this->recreateSalesByCustomerView();
        $this->recreateArAgingView();
        $this->recreateTopDebtorsView();
        $this->recreateAccountsReceivableSummaryView();
        $this->recreateCustomerReturnsDetailView();
        $this->recreateSalesByProductView();
        $this->recreateStockOnHandView();
    }

    public function down(): void
    {
        // Prior migrations / schema deploy restore earlier definitions.
    }

    protected function recreateSalesByCustomerView(): void
    {
        $legacy = $this->legacySalesFilter;

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
    AND s.status = 'completed'
    AND {$legacy}
LEFT JOIN routes r ON c.route_id = r.id
LEFT JOIN customer_invoices ci ON ci.customer_num = c.customer_num
    AND ci.organization_id = c.organization_id
    AND ci.deleted_at IS NULL
WHERE c.deleted_at IS NULL
GROUP BY c.organization_id, c.customer_num, c.customer_name, c.phone_number, r.route_name, c.current_balance
SQL);
    }

    protected function recreateArAgingView(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_ar_aging');
        DB::statement(<<<SQL
CREATE VIEW v_ar_aging AS
SELECT
    ci.organization_id,
    ci.customer_num,
    c.customer_name,
    c.phone_number,
    ci.invoice_number,
    ci.invoice_date,
    ci.due_date,
    ci.invoice_total,
    ci.amount_paid,
    ci.balance_due,
    ci.payment_status,
    DATEDIFF(CURRENT_DATE, ci.invoice_date) AS days_outstanding,
    CASE
        WHEN DATEDIFF(CURRENT_DATE, ci.invoice_date) <= 30 THEN '0-30 days'
        WHEN DATEDIFF(CURRENT_DATE, ci.invoice_date) <= 60 THEN '31-60 days'
        WHEN DATEDIFF(CURRENT_DATE, ci.invoice_date) <= 90 THEN '61-90 days'
        ELSE 'Over 90 days'
    END AS aging_bucket
FROM customer_invoices ci
JOIN customers c ON ci.customer_num = c.customer_num
    AND ci.organization_id = c.organization_id
WHERE ci.payment_status IN (0, 1) AND ci.deleted_at IS NULL
SQL);
    }

    protected function recreateTopDebtorsView(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_top_debtors');
        DB::statement(<<<SQL
CREATE VIEW v_top_debtors AS
SELECT
    c.organization_id,
    c.customer_num,
    c.customer_name,
    c.phone_number,
    r.route_name,
    c.current_balance,
    COUNT(DISTINCT ci.id) AS open_invoices,
    COALESCE(SUM(ci.balance_due), 0) AS invoice_balance
FROM customers c
LEFT JOIN routes r ON c.route_id = r.id
LEFT JOIN customer_invoices ci ON ci.customer_num = c.customer_num
    AND ci.organization_id = c.organization_id
    AND ci.payment_status IN (0, 1)
    AND ci.deleted_at IS NULL
WHERE c.deleted_at IS NULL AND (c.current_balance > 0 OR ci.id IS NOT NULL)
GROUP BY c.organization_id, c.customer_num, c.customer_name, c.phone_number, r.route_name, c.current_balance
HAVING c.current_balance > 0 OR COALESCE(SUM(ci.balance_due), 0) > 0
SQL);
    }

    protected function recreateAccountsReceivableSummaryView(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_accounts_receivable_summary');
        DB::statement(<<<SQL
CREATE VIEW v_accounts_receivable_summary AS
SELECT
    c.organization_id,
    c.customer_num,
    c.customer_name,
    c.phone_number,
    r.route_name,
    COALESCE(c.current_balance, 0) AS customer_balance,
    COALESCE(inv.open_invoice_total, 0) AS invoice_balance_due,
    COALESCE(inv.open_invoice_count, 0) AS open_invoices,
    COALESCE(credit.credit_sales_outstanding, 0) AS credit_sales_outstanding,
    COALESCE(c.current_balance, 0)
        + COALESCE(inv.open_invoice_total, 0)
        + COALESCE(credit.credit_sales_outstanding, 0) AS total_outstanding
FROM customers c
LEFT JOIN routes r ON c.route_id = r.id
LEFT JOIN (
    SELECT
        ci.organization_id,
        ci.customer_num,
        SUM(ci.balance_due) AS open_invoice_total,
        COUNT(*) AS open_invoice_count
    FROM customer_invoices ci
    WHERE ci.payment_status IN (0, 1) AND ci.deleted_at IS NULL
    GROUP BY ci.organization_id, ci.customer_num
) inv ON inv.customer_num = c.customer_num AND inv.organization_id = c.organization_id
LEFT JOIN (
    SELECT
        s.organization_id,
        s.customer_num,
        SUM(s.order_total - s.amount_paid) AS credit_sales_outstanding
    FROM sales s
    WHERE s.status = 'completed'
        AND s.is_credit_sale = 1
        AND s.payment_status IN ('unpaid', 'partial')
        AND s.customer_num IS NOT NULL
    GROUP BY s.organization_id, s.customer_num
) credit ON credit.customer_num = c.customer_num AND credit.organization_id = c.organization_id
WHERE c.deleted_at IS NULL
    AND (
        COALESCE(c.current_balance, 0) > 0
        OR COALESCE(inv.open_invoice_total, 0) > 0
        OR COALESCE(credit.credit_sales_outstanding, 0) > 0
    )
SQL);
    }

    protected function recreateCustomerReturnsDetailView(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('customer_returns')) {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS v_customer_returns_detail');
        DB::statement(<<<SQL
CREATE VIEW v_customer_returns_detail AS
SELECT
    cr.return_date AS return_date,
    cr.organization_id,
    cr.branch_id,
    cr.customer_num,
    COALESCE(c.customer_name, s.customer_name_override, 'Walk-in') AS customer_name,
    crl.product_code COLLATE utf8mb4_0900_ai_ci AS product_code,
    COALESCE(crl.product_name COLLATE utf8mb4_0900_ai_ci, p.product_name) AS product_name,
    crl.return_qty AS quantity,
    CAST(cr.stock_location AS CHAR(10) CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_ai_ci AS stock_location,
    cr.reason COLLATE utf8mb4_0900_ai_ci AS reason,
    u.username AS returned_by
FROM customer_return_lines crl
JOIN customer_returns cr ON crl.customer_return_id = cr.id
JOIN users u ON cr.returned_by = u.id
LEFT JOIN customers c ON cr.customer_num = c.customer_num AND cr.organization_id = c.organization_id
LEFT JOIN products p ON crl.product_code COLLATE utf8mb4_0900_ai_ci = p.product_code
    AND p.organization_id = cr.organization_id
LEFT JOIN sales s ON cr.sale_id = s.id
WHERE cr.status = 'approved'

UNION ALL

SELECT
    COALESCE(DATE(r.created_at), CURRENT_DATE) AS return_date,
    s.organization_id,
    r.branch_id,
    s.customer_num,
    COALESCE(c.customer_name, s.customer_name_override, 'Walk-in') AS customer_name,
    r.product_code,
    p.product_name,
    r.quantity,
    CAST(NULL AS CHAR(10) CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_ai_ci AS stock_location,
    r.reason,
    u.username AS returned_by
FROM returns r
JOIN users u ON r.returned_by = u.id
JOIN sales s ON r.sale_id = s.id
JOIN products p ON r.product_code = p.product_code AND p.organization_id = s.organization_id
LEFT JOIN customers c ON s.customer_num = c.customer_num AND s.organization_id = c.organization_id
WHERE NOT EXISTS (
    SELECT 1 FROM customer_return_lines crl WHERE crl.legacy_return_id = r.id
)
SQL);
    }

    protected function recreateSalesByProductView(): void
    {
        $legacy = $this->legacySalesFilter;

        DB::statement('DROP VIEW IF EXISTS v_sales_by_product');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_product AS
SELECT
    s.organization_id,
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
JOIN products p ON si.product_code = p.product_code AND p.organization_id = s.organization_id
WHERE s.status = 'completed' AND {$legacy}
GROUP BY s.organization_id, si.product_code, p.product_name, DATE(s.created_at), s.branch_id, s.channel, si.uom
SQL);
    }

    protected function recreateStockOnHandView(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_stock_on_hand');
        DB::statement(<<<SQL
CREATE VIEW v_stock_on_hand AS
SELECT
    b.organization_id,
    cs.branch_id,
    p.product_code,
    p.product_name,
    p.unit_price AS wholesale_price,
    u.full_name AS uom_name,
    u.conversion_factor,
    cs.shop_quantity,
    cs.store_quantity,
    (cs.shop_quantity + cs.store_quantity) AS total_base_units,
    p.reorder_point,
    p.low_stock_alert_enabled,
    CASE
        WHEN (cs.shop_quantity + cs.store_quantity) <= p.reorder_point THEN 'REORDER'
        ELSE 'OK'
    END AS product_alert,
    rps.max_qty_measure,
    rps.markup_price,
    rps.wholesale_markup_price
FROM current_stock cs
JOIN branches b ON b.id = cs.branch_id
JOIN products p ON cs.product_code = p.product_code AND p.organization_id = b.organization_id
JOIN uoms u ON p.unit_id = u.id
LEFT JOIN retail_package_settings rps ON p.product_code = rps.product_code
WHERE p.deleted_at IS NULL
SQL);
    }
};
