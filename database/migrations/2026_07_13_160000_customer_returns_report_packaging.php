<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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
    um.uom_type,
    COALESCE(si.on_wholesale_retail, 0) AS on_wholesale_retail,
    COALESCE(si.uom, um.full_name) AS sold_uom
FROM customer_return_lines crl
JOIN customer_returns cr ON crl.customer_return_id = cr.id
JOIN users u ON cr.returned_by = u.id
LEFT JOIN customers c ON cr.customer_num = c.customer_num AND cr.organization_id = c.organization_id
LEFT JOIN products p ON crl.product_code COLLATE utf8mb4_0900_ai_ci = p.product_code
    AND p.organization_id = cr.organization_id
LEFT JOIN uoms um ON um.id = p.unit_id
LEFT JOIN sale_items si ON crl.sale_item_id = si.id
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
    um.uom_type,
    0 AS on_wholesale_retail,
    COALESCE(r.uom, um.full_name) AS sold_uom
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
    }

    public function down(): void
    {
        // Prior migration 2026_07_13_120000 recreates the view without sold_uom.
    }
};
