<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_purchases_by_supplier');
        DB::statement(<<<'SQL'
CREATE VIEW v_purchases_by_supplier AS
SELECT
    s.organization_id,
    s.id AS supplier_id,
    s.supplier_code,
    s.supplier_name,
    l.lpo_no,
    l.created_at AS order_date,
    l.due_date,
    l.total_amount,
    l.lpo_status_code,
    ls.status_name,
    COUNT(t.id) AS line_items,
    SUM(t.ordered_qty) AS total_qty_ordered,
    SUM(COALESCE(t.received_qty, 0)) AS total_qty_received,
    SUM(GREATEST(t.ordered_qty - COALESCE(t.received_qty, 0), 0)) AS total_qty_pending,
    SUM(GREATEST(t.ordered_qty - COALESCE(t.received_qty, 0), 0) * COALESCE(t.cost_price, 0)) AS pending_value
FROM suppliers s
JOIN lpo_mst l ON l.supplier_id = s.id AND l.organization_id = s.organization_id
JOIN lpo_statuses ls ON l.lpo_status_code = ls.status_code
JOIN lpo_txn t ON t.lpo_no = l.lpo_no
WHERE s.deleted_at IS NULL
  AND l.deleted_at IS NULL
GROUP BY
    s.organization_id, s.id, s.supplier_code, s.supplier_name,
    l.lpo_no, l.created_at, l.due_date, l.total_amount, l.lpo_status_code, ls.status_name
SQL);

        DB::statement('DROP VIEW IF EXISTS v_open_lpo_lines');
        DB::statement(<<<'SQL'
CREATE VIEW v_open_lpo_lines AS
SELECT
    l.organization_id,
    l.lpo_no,
    l.supplier_id,
    sup.supplier_name,
    l.lpo_status_code,
    ls.status_name,
    l.created_at AS order_date,
    l.due_date,
    t.product_code,
    p.product_name,
    p.unit_id,
    u.full_name AS uom_name,
    u.conversion_factor,
    u.small_packaging_label,
    u.middle_packaging_label,
    u.middle_factor,
    u.uom_type,
    t.ordered_qty,
    COALESCE(t.received_qty, 0) AS received_qty,
    (t.ordered_qty - COALESCE(t.received_qty, 0)) AS pending_qty,
    t.cost_price,
    ROUND((t.ordered_qty - COALESCE(t.received_qty, 0)) * COALESCE(t.cost_price, 0), 2) AS pending_value,
    t.uom
FROM lpo_txn t
JOIN lpo_mst l ON t.lpo_no = l.lpo_no
JOIN suppliers sup ON l.supplier_id = sup.id AND sup.organization_id = l.organization_id
JOIN lpo_statuses ls ON l.lpo_status_code = ls.status_code
JOIN products p ON t.product_code = p.product_code AND p.organization_id = l.organization_id AND p.deleted_at IS NULL
LEFT JOIN uoms u ON u.id = p.unit_id
WHERE l.deleted_at IS NULL
  AND l.lpo_status_code IN (2, 3)
  AND (t.ordered_qty - COALESCE(t.received_qty, 0)) > 0.0001
SQL);

        DB::statement('DROP VIEW IF EXISTS v_top_debtors');
        DB::statement(<<<'SQL'
CREATE VIEW v_top_debtors AS
SELECT
    c.organization_id,
    c.customer_num,
    c.customer_name,
    c.phone_number,
    r.route_name,
    COALESCE(c.current_balance, 0) AS current_balance,
    COUNT(DISTINCT ci.id) AS open_invoices,
    COALESCE(SUM(GREATEST(ci.invoice_total - ci.amount_paid, 0)), 0) AS invoice_balance,
    GREATEST(
        COALESCE(c.current_balance, 0),
        COALESCE(SUM(GREATEST(ci.invoice_total - ci.amount_paid, 0)), 0)
    ) AS outstanding_balance
FROM customers c
LEFT JOIN routes r ON c.route_id = r.id
LEFT JOIN customer_invoices ci ON ci.customer_num = c.customer_num
    AND ci.organization_id = c.organization_id
    AND ci.payment_status IN (0, 1)
    AND ci.deleted_at IS NULL
WHERE c.deleted_at IS NULL
  AND (
    COALESCE(c.current_balance, 0) > 0
    OR ci.id IS NOT NULL
  )
GROUP BY
    c.organization_id, c.customer_num, c.customer_name, c.phone_number,
    r.route_name, c.current_balance
HAVING
    COALESCE(c.current_balance, 0) > 0
    OR COALESCE(SUM(GREATEST(ci.invoice_total - ci.amount_paid, 0)), 0) > 0
SQL);

        DB::statement('DROP VIEW IF EXISTS v_customer_returns_detail');
        DB::statement(<<<'SQL'
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
    u.username AS returned_by,
    um.full_name AS uom_name,
    um.conversion_factor,
    um.small_packaging_label,
    um.middle_packaging_label,
    um.middle_factor,
    um.uom_type
FROM customer_return_lines crl
JOIN customer_returns cr ON crl.customer_return_id = cr.id
JOIN users u ON cr.returned_by = u.id
LEFT JOIN customers c ON cr.customer_num = c.customer_num AND cr.organization_id = c.organization_id
LEFT JOIN products p ON crl.product_code COLLATE utf8mb4_0900_ai_ci = p.product_code
    AND p.organization_id = cr.organization_id
LEFT JOIN uoms um ON um.id = p.unit_id
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
    u.username AS returned_by,
    um.full_name AS uom_name,
    um.conversion_factor,
    um.small_packaging_label,
    um.middle_packaging_label,
    um.middle_factor,
    um.uom_type
FROM returns r
JOIN users u ON r.returned_by = u.id
JOIN sales s ON r.sale_id = s.id
JOIN products p ON r.product_code = p.product_code AND p.organization_id = s.organization_id
LEFT JOIN uoms um ON um.id = p.unit_id
LEFT JOIN customers c ON s.customer_num = c.customer_num AND s.organization_id = c.organization_id
WHERE NOT EXISTS (
    SELECT 1 FROM customer_return_lines crl WHERE crl.legacy_return_id = r.id
)
SQL);

        $legacy = \App\Services\Sales\CentrixSalesScope::legacyExcludeSql('s');

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
    DATE(s.completed_at) AS sale_date,
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
WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
GROUP BY
    s.organization_id, sup.id, sup.supplier_name, sup.supplier_code,
    si.product_code, p.product_name, DATE(s.completed_at), s.branch_id, s.channel
SQL);

        DB::statement('DROP VIEW IF EXISTS v_category_sales');
        DB::statement(<<<SQL
CREATE VIEW v_category_sales AS
SELECT
    s.organization_id,
    DATE(s.completed_at) AS sale_date,
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
WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
GROUP BY
    s.organization_id, DATE(s.completed_at), s.branch_id,
    c.id, c.category_name, sc.id, sc.subcategory_name,
    si.product_code, p.product_name
SQL);

        DB::statement('DROP VIEW IF EXISTS v_stock_reservations_active');
        DB::statement(<<<'SQL'
CREATE VIEW v_stock_reservations_active AS
SELECT
    b.organization_id,
    sr.branch_id,
    sr.product_code,
    p.product_name,
    sr.stock_location,
    SUM(sr.quantity) AS reserved_qty,
    COUNT(*) AS reservation_count,
    uom.full_name AS uom_name,
    uom.conversion_factor,
    uom.small_packaging_label,
    uom.middle_packaging_label,
    uom.middle_factor,
    uom.uom_type
FROM stock_reservations sr
JOIN products p ON sr.product_code = p.product_code
JOIN branches b ON sr.branch_id = b.id AND p.organization_id = b.organization_id
LEFT JOIN uoms uom ON uom.id = p.unit_id
WHERE sr.released_at IS NULL
  AND p.deleted_at IS NULL
GROUP BY
    b.organization_id, sr.branch_id, sr.product_code, p.product_name, sr.stock_location,
    uom.full_name, uom.conversion_factor, uom.small_packaging_label,
    uom.middle_packaging_label, uom.middle_factor, uom.uom_type
SQL);
    }

    public function down(): void
    {
        // Views are recreated by later migrations / schema; leave current definitions.
    }
};
