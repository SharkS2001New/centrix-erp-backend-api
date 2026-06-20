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
    u.username AS salesperson,
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

        DB::statement('DROP VIEW IF EXISTS v_dispatch_trips_summary');
        DB::statement(<<<'SQL'
CREATE VIEW v_dispatch_trips_summary AS
SELECT
    dt.branch_id,
    dt.scheduled_date,
    dt.trip_code,
    r.route_name,
    d.full_name AS driver_name,
    v.plate_number AS vehicle_plate,
    dt.status,
    COUNT(DISTINCT dts.sale_id) AS order_count,
    dt.expected_cash,
    dt.collected_cash,
    dt.cash_variance,
    dt.departed_at,
    dt.completed_at
FROM dispatch_trips dt
LEFT JOIN routes r ON dt.route_id = r.id
LEFT JOIN drivers d ON dt.driver_id = d.id
LEFT JOIN vehicles v ON dt.vehicle_id = v.id
LEFT JOIN dispatch_trip_sales dts ON dts.trip_id = dt.id
GROUP BY dt.id, dt.branch_id, dt.scheduled_date, dt.trip_code, r.route_name, d.full_name,
         v.plate_number, dt.status, dt.expected_cash, dt.collected_cash, dt.cash_variance,
         dt.departed_at, dt.completed_at
SQL);

        DB::statement('DROP VIEW IF EXISTS v_trip_cash_settlement');
        DB::statement(<<<'SQL'
CREATE VIEW v_trip_cash_settlement AS
SELECT
    dt.branch_id,
    dt.scheduled_date,
    dt.trip_code,
    r.route_name,
    d.full_name AS driver_name,
    dt.expected_cash,
    dt.collected_cash,
    dt.cash_variance,
    dt.status,
    dt.settled_at,
    su.username AS settled_by
FROM dispatch_trips dt
LEFT JOIN routes r ON dt.route_id = r.id
LEFT JOIN drivers d ON dt.driver_id = d.id
LEFT JOIN users su ON dt.settled_by = su.id
WHERE dt.status IN ('completed', 'in_transit')
   OR dt.settled_at IS NOT NULL
   OR dt.collected_cash IS NOT NULL
SQL);

        DB::statement('DROP VIEW IF EXISTS v_pod_compliance');
        DB::statement(<<<'SQL'
CREATE VIEW v_pod_compliance AS
SELECT
    pr.branch_id,
    DATE(pr.captured_at) AS capture_date,
    r.route_name,
    d.full_name AS driver_name,
    COUNT(pr.id) AS pod_count,
    SUM(CASE WHEN pr.status = 'complete' THEN 1 ELSE 0 END) AS complete_count,
    SUM(CASE WHEN pr.status = 'partial' THEN 1 ELSE 0 END) AS partial_count,
    SUM(CASE WHEN pr.status = 'refused' THEN 1 ELSE 0 END) AS refused_count
FROM pod_records pr
LEFT JOIN sales s ON pr.sale_id = s.id
LEFT JOIN routes r ON s.route_id = r.id
LEFT JOIN dispatch_trips dt ON pr.trip_id = dt.id
LEFT JOIN drivers d ON dt.driver_id = d.id
GROUP BY pr.branch_id, DATE(pr.captured_at), r.route_name, d.full_name
SQL);

        DB::statement('DROP VIEW IF EXISTS v_driver_deliveries');
        DB::statement(<<<'SQL'
CREATE VIEW v_driver_deliveries AS
SELECT
    dt.branch_id,
    DATE(s.completed_at) AS delivery_date,
    d.full_name AS driver_name,
    r.route_name,
    COUNT(DISTINCT s.id) AS deliveries,
    SUM(s.order_total) AS total_value,
    SUM(CASE WHEN s.is_credit_sale = 0 THEN s.order_total ELSE 0 END) AS cash_value,
    SUM(CASE WHEN s.is_credit_sale = 1 THEN s.order_total ELSE 0 END) AS credit_value
FROM sales s
JOIN dispatch_trip_sales dts ON dts.sale_id = s.id
JOIN dispatch_trips dt ON dts.trip_id = dt.id
JOIN drivers d ON dt.driver_id = d.id
LEFT JOIN routes r ON s.route_id = r.id
WHERE s.status = 'completed'
  AND s.archived = 0
GROUP BY dt.branch_id, DATE(s.completed_at), d.full_name, r.route_name
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_driver_deliveries');
        DB::statement('DROP VIEW IF EXISTS v_pod_compliance');
        DB::statement('DROP VIEW IF EXISTS v_trip_cash_settlement');
        DB::statement('DROP VIEW IF EXISTS v_dispatch_trips_summary');

        DB::statement('DROP VIEW IF EXISTS v_route_loading_summary');
        DB::statement(<<<'SQL'
CREATE VIEW v_route_loading_summary AS
SELECT
    DATE(s.created_at) AS loading_date,
    r.route_name,
    r.route_markup_price,
    s.cashier_id,
    u.username AS salesperson,
    COUNT(DISTINCT s.id) AS total_orders,
    COUNT(si.id) AS total_items,
    SUM(si.quantity) AS total_qty,
    SUM(si.amount) AS total_value,
    SUM(s.order_total) AS grand_total,
    SUM(CASE WHEN s.status='completed' THEN s.order_total ELSE 0 END) AS delivered_value,
    SUM(CASE WHEN s.is_credit_sale=0 THEN s.order_total ELSE 0 END) AS cash_collected,
    SUM(CASE WHEN s.is_credit_sale=1 THEN s.order_total ELSE 0 END) AS credit_outstanding
FROM sales s
JOIN routes r ON s.route_id = r.id
JOIN users u ON s.cashier_id = u.id
JOIN sale_items si ON si.sale_id = s.id
WHERE s.channel = 'mobile'
GROUP BY DATE(s.created_at), s.route_id, s.cashier_id
SQL);
    }
};
