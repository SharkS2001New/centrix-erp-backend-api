<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const UOM_COLUMNS = <<<'SQL'
    uom.full_name AS uom_name,
    uom.conversion_factor,
    uom.small_packaging_label,
    uom.middle_packaging_label,
    uom.middle_factor,
    uom.uom_type
SQL;

    public function up(): void
    {
        $uomColumns = self::UOM_COLUMNS;

        DB::statement('DROP VIEW IF EXISTS v_stock_valuation');
        DB::statement(<<<SQL
CREATE VIEW v_stock_valuation AS
SELECT
    b.organization_id,
    cs.branch_id,
    p.product_code,
    p.product_name,
    cs.shop_quantity,
    cs.store_quantity,
    (cs.shop_quantity + cs.store_quantity) AS total_qty,
    {$uomColumns},
    p.last_cost_price,
    COALESCE(
        NULLIF(p.last_cost_price, 0),
        (
            SELECT sr.cost_price
            FROM stock_receipts sr
            WHERE sr.organization_id = b.organization_id
              AND sr.product_code = p.product_code
              AND sr.cost_price IS NOT NULL
              AND sr.cost_price > 0
            ORDER BY sr.id DESC
            LIMIT 1
        ),
        0
    ) AS effective_unit_cost,
    p.unit_price,
    (cs.shop_quantity / GREATEST(COALESCE(uom.conversion_factor, 1), 1)) * COALESCE(
        NULLIF(p.last_cost_price, 0),
        (
            SELECT sr.cost_price
            FROM stock_receipts sr
            WHERE sr.organization_id = b.organization_id
              AND sr.product_code = p.product_code
              AND sr.cost_price IS NOT NULL
              AND sr.cost_price > 0
            ORDER BY sr.id DESC
            LIMIT 1
        ),
        0
    ) AS shop_cost_value,
    (cs.store_quantity / GREATEST(COALESCE(uom.conversion_factor, 1), 1)) * COALESCE(
        NULLIF(p.last_cost_price, 0),
        (
            SELECT sr.cost_price
            FROM stock_receipts sr
            WHERE sr.organization_id = b.organization_id
              AND sr.product_code = p.product_code
              AND sr.cost_price IS NOT NULL
              AND sr.cost_price > 0
            ORDER BY sr.id DESC
            LIMIT 1
        ),
        0
    ) AS store_cost_value,
    ((cs.shop_quantity + cs.store_quantity) / GREATEST(COALESCE(uom.conversion_factor, 1), 1)) * COALESCE(
        NULLIF(p.last_cost_price, 0),
        (
            SELECT sr.cost_price
            FROM stock_receipts sr
            WHERE sr.organization_id = b.organization_id
              AND sr.product_code = p.product_code
              AND sr.cost_price IS NOT NULL
              AND sr.cost_price > 0
            ORDER BY sr.id DESC
            LIMIT 1
        ),
        0
    ) AS cost_value,
    ((cs.shop_quantity + cs.store_quantity) / GREATEST(COALESCE(uom.conversion_factor, 1), 1)) * p.unit_price AS retail_value
FROM current_stock cs
JOIN branches b ON b.id = cs.branch_id
JOIN products p ON cs.product_code = p.product_code AND p.organization_id = b.organization_id
JOIN uoms uom ON uom.id = p.unit_id
WHERE p.deleted_at IS NULL
SQL);

        DB::statement('DROP VIEW IF EXISTS v_damages_summary');
        DB::statement(<<<SQL
CREATE VIEW v_damages_summary AS
SELECT
    DATE(d.created_at) AS damage_date,
    d.branch_id,
    d.product_code,
    p.product_name,
    d.stock_location,
    d.package_type,
    SUM(d.quantity) AS total_qty,
    COUNT(*) AS incident_count,
    MAX(uom.full_name) AS uom_name,
    MAX(uom.conversion_factor) AS conversion_factor,
    MAX(uom.small_packaging_label) AS small_packaging_label,
    MAX(uom.middle_packaging_label) AS middle_packaging_label,
    MAX(uom.middle_factor) AS middle_factor,
    MAX(uom.uom_type) AS uom_type
FROM damages d
JOIN products p ON d.product_code = p.product_code
JOIN uoms uom ON uom.id = p.unit_id
GROUP BY DATE(d.created_at), d.branch_id, d.product_code, p.product_name, d.stock_location, d.package_type
SQL);

        DB::statement('DROP VIEW IF EXISTS v_stock_transfers');
        DB::statement(<<<SQL
CREATE VIEW v_stock_transfers AS
SELECT
    DATE(smh.created_at) AS transfer_date,
    smh.branch_id,
    b.organization_id,
    smh.product_code,
    MAX(p.product_name) AS product_name,
    smh.from_location,
    smh.to_location,
    SUM(smh.quantity_moved) AS total_moved,
    COUNT(*) AS transfer_count,
    MAX(uom.full_name) AS uom_name,
    MAX(uom.conversion_factor) AS conversion_factor,
    MAX(uom.small_packaging_label) AS small_packaging_label,
    MAX(uom.middle_packaging_label) AS middle_packaging_label,
    MAX(uom.middle_factor) AS middle_factor,
    MAX(uom.uom_type) AS uom_type
FROM stock_movement_history smh
JOIN branches b ON smh.branch_id = b.id
JOIN products p ON smh.product_code = p.product_code
    AND p.organization_id = b.organization_id
JOIN uoms uom ON uom.id = p.unit_id
GROUP BY DATE(smh.created_at), smh.branch_id, b.organization_id, smh.product_code, smh.from_location, smh.to_location
SQL);

        DB::statement('DROP VIEW IF EXISTS v_stock_receipts_detail');
        DB::statement(<<<SQL
CREATE VIEW v_stock_receipts_detail AS
SELECT
    DATE(sr.created_at) AS receipt_date,
    sr.branch_id,
    sr.organization_id,
    sr.product_code,
    p.product_name,
    sr.units_received,
    sr.stock_location,
    sr.cost_price,
    (sr.units_received * COALESCE(sr.cost_price, 0)) AS line_cost,
    sr.invoice_number,
    u.username AS received_by,
    {$uomColumns}
FROM stock_receipts sr
JOIN products p ON sr.product_code = p.product_code
JOIN uoms uom ON uom.id = p.unit_id
JOIN users u ON sr.received_by = u.id
SQL);

        DB::statement('DROP VIEW IF EXISTS v_stock_reservations_active');
        DB::statement(<<<SQL
CREATE VIEW v_stock_reservations_active AS
SELECT
    sr.branch_id,
    sr.product_code,
    p.product_name,
    sr.stock_location,
    SUM(sr.quantity) AS reserved_qty,
    COUNT(*) AS reservation_count,
    MAX(uom.full_name) AS uom_name,
    MAX(uom.conversion_factor) AS conversion_factor,
    MAX(uom.small_packaging_label) AS small_packaging_label,
    MAX(uom.middle_packaging_label) AS middle_packaging_label,
    MAX(uom.middle_factor) AS middle_factor,
    MAX(uom.uom_type) AS uom_type
FROM stock_reservations sr
JOIN products p ON sr.product_code = p.product_code
JOIN uoms uom ON uom.id = p.unit_id
WHERE sr.released_at IS NULL
GROUP BY sr.branch_id, sr.product_code, p.product_name, sr.stock_location
SQL);
    }

    public function down(): void
    {
        // Views are restored by prior migrations if rolled back individually.
    }
};
