<?php

use App\Services\Sales\CentrixSalesScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected string $legacySalesFilter;

    public function __construct()
    {
        $this->legacySalesFilter = CentrixSalesScope::legacyExcludeSql('s');
    }

    public function up(): void
    {
        $legacy = $this->legacySalesFilter;

        DB::statement('DROP VIEW IF EXISTS v_daily_sales');
        DB::statement(<<<SQL
CREATE VIEW v_daily_sales AS
SELECT
    s.organization_id,
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
GROUP BY s.organization_id, DATE(s.completed_at), s.branch_id, b.branch_name, s.channel
SQL);

        DB::statement('DROP VIEW IF EXISTS v_sales_by_channel');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_channel AS
SELECT
    s.organization_id,
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
GROUP BY s.organization_id, DATE(s.completed_at), s.branch_id, b.branch_name, s.channel, s.payment_status
SQL);

        DB::statement('DROP VIEW IF EXISTS v_sales_by_user');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_user AS
SELECT
    s.organization_id,
    DATE(s.completed_at) AS sale_date,
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
WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
GROUP BY s.organization_id, DATE(s.completed_at), s.branch_id, s.cashier_id, u.full_name, s.channel
SQL);

        DB::statement('DROP VIEW IF EXISTS v_vat_collected');
        DB::statement(<<<SQL
CREATE VIEW v_vat_collected AS
SELECT
    s.organization_id,
    DATE(s.completed_at) AS sale_date,
    s.branch_id,
    b.branch_name,
    s.channel,
    SUM(s.total_vat) AS vat_collected,
    SUM(s.order_total) AS gross_sales,
    COUNT(*) AS orders
FROM sales s
JOIN branches b ON s.branch_id = b.id
WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
GROUP BY s.organization_id, DATE(s.completed_at), s.branch_id, b.branch_name, s.channel
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
    SUM(si.quantity) AS qty_sold,
    SUM(si.amount) AS revenue,
    SUM(si.product_vat) AS vat,
    SUM(si.discount_given) AS discounts
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN products p ON si.product_code = p.product_code AND p.organization_id = s.organization_id
JOIN sub_categories sc ON p.subcategory_id = sc.id
JOIN categories c ON sc.category_id = c.id
WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
GROUP BY s.organization_id, DATE(s.completed_at), s.branch_id, c.id, c.category_name, sc.id, sc.subcategory_name
SQL);

        DB::statement('DROP VIEW IF EXISTS v_discount_summary');
        DB::statement(<<<SQL
CREATE VIEW v_discount_summary AS
SELECT
    s.organization_id,
    DATE(s.completed_at) AS sale_date,
    s.branch_id,
    s.channel,
    COUNT(DISTINCT s.id) AS orders_with_discount,
    SUM(si.discount_given) AS total_discount,
    SUM(si.amount) AS net_line_sales
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
WHERE s.status = 'completed' AND s.archived = 0 AND si.discount_given > 0 AND {$legacy}
GROUP BY s.organization_id, DATE(s.completed_at), s.branch_id, s.channel
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
WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
GROUP BY s.organization_id, DATE(sp.paid_at), s.branch_id, s.channel, pm.method_code, pm.method_name
SQL);
    }

    public function down(): void
    {
        // Prior migrations / schema deploy restore earlier definitions.
    }
};
