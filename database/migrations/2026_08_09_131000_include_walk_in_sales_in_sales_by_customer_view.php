<?php

use App\Services\Sales\CentrixSalesScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Include walk-in / cash-sale rows (sales.customer_num IS NULL) in
 * v_sales_by_customer so customer report maths match total sales.
 *
 * Registered customers stay as today; walk-ins appear as one "Walk-in" bucket
 * per organization (no AR — cash sales are not invoiced).
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacy = CentrixSalesScope::legacyExcludeSql('s');
        $statuses = CentrixSalesScope::reportPipelineStatusSql('s.status');

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
    COALESCE(SUM(s.order_total), 0) AS total_purchased,
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

UNION ALL

SELECT
    s.organization_id,
    NULL AS customer_num,
    'Walk-in' AS customer_name,
    NULL AS phone_number,
    NULL AS route_name,
    COUNT(DISTINCT s.id) AS total_orders,
    COALESCE(SUM(s.order_total), 0) AS total_purchased,
    0 AS total_invoiced,
    0 AS total_paid,
    0 AS total_outstanding,
    0 AS ar_balance
FROM sales s
WHERE s.customer_num IS NULL
    AND {$statuses}
    AND s.archived = 0
    AND {$legacy}
GROUP BY s.organization_id
SQL);
    }

    public function down(): void
    {
        $legacy = CentrixSalesScope::legacyExcludeSql('s');
        $statuses = CentrixSalesScope::reportPipelineStatusSql('s.status');

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
    }
};
