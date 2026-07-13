<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_route_loading_summary');
        DB::statement(<<<'SQL'
CREATE VIEW v_route_loading_summary AS
SELECT
    DATE(COALESCE(s.delivery_date, s.created_at)) AS loading_date,
    r.route_name,
    s.channel,
    s.cashier_id,
    COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS salesperson,
    COUNT(DISTINCT s.id) AS total_orders,
    COUNT(si.id) AS total_items,
    SUM(si.quantity) AS total_qty,
    SUM(si.amount) AS total_value,
    SUM(s.order_total) AS grand_total,
    SUM(CASE WHEN s.status = 'completed' THEN s.order_total ELSE 0 END) AS delivered_value,
    SUM(CASE WHEN s.is_credit_sale = 0 THEN s.order_total ELSE 0 END) AS cash_collected,
    SUM(CASE WHEN s.is_credit_sale = 1 THEN s.order_total ELSE 0 END) AS credit_outstanding
FROM sales s
JOIN routes r ON s.route_id = r.id
JOIN users u ON s.cashier_id = u.id
JOIN sale_items si ON si.sale_id = s.id
WHERE s.route_id IS NOT NULL
  AND s.channel IN ('mobile', 'pos')
  AND s.archived = 0
GROUP BY DATE(COALESCE(s.delivery_date, s.created_at)), s.route_id, s.cashier_id, s.channel
SQL);
    }

    public function down(): void
    {
        // Restored by 2026_06_20_000001_add_distribution_report_views on rollback.
    }
};
