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
    t.product_code,
    p.product_name,
    t.ordered_qty AS total_qty_ordered,
    COALESCE(t.received_qty, 0) AS total_qty_received,
    GREATEST(t.ordered_qty - COALESCE(t.received_qty, 0), 0) AS total_qty_pending,
    ROUND(
        GREATEST(t.ordered_qty - COALESCE(t.received_qty, 0), 0) * COALESCE(t.cost_price, 0),
        2
    ) AS pending_value,
    u.full_name AS uom_name,
    u.conversion_factor,
    u.small_packaging_label,
    u.middle_packaging_label,
    u.middle_factor,
    u.uom_type
FROM suppliers s
JOIN lpo_mst l ON l.supplier_id = s.id AND l.organization_id = s.organization_id
JOIN lpo_statuses ls ON l.lpo_status_code = ls.status_code
JOIN lpo_txn t ON t.lpo_no = l.lpo_no
JOIN products p ON t.product_code = p.product_code AND p.organization_id = s.organization_id AND p.deleted_at IS NULL
LEFT JOIN uoms u ON u.id = p.unit_id
WHERE s.deleted_at IS NULL
  AND l.deleted_at IS NULL
SQL);
    }

    public function down(): void
    {
        // Prior definition restored by 2026_07_13_120000 migration when rolled back in order.
    }
};
