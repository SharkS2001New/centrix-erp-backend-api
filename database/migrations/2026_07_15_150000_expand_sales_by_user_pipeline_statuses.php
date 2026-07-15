<?php

use App\Services\Sales\CentrixSalesScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales by User should show work done by cashiers across the sales pipeline,
 * not only completed orders. Date bucketing uses completed_at when present,
 * otherwise created_at so booked/processed orders still appear in the range.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacy = CentrixSalesScope::legacyExcludeSql('s');

        DB::statement('DROP VIEW IF EXISTS v_sales_by_user');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_user AS
SELECT
    s.organization_id,
    DATE(COALESCE(s.completed_at, s.created_at)) AS sale_date,
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
WHERE s.status IN (
        'booked',
        'pending',
        'unpaid',
        'pending_payment',
        'paid',
        'processed',
        'delivered',
        'completed'
    )
  AND s.archived = 0
  AND s.cashier_id IS NOT NULL
  AND {$legacy}
GROUP BY
    s.organization_id,
    DATE(COALESCE(s.completed_at, s.created_at)),
    s.branch_id,
    s.cashier_id,
    u.full_name,
    s.channel
SQL);
    }

    public function down(): void
    {
        $legacy = CentrixSalesScope::legacyExcludeSql('s');

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
    }
};
