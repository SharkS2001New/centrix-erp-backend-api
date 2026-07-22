<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replace per-row correlated receipt-cost subqueries in v_stock_valuation with a
 * single join to latest positive receipt cost per product.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_stock_valuation');
        DB::statement(<<<'SQL'
CREATE VIEW v_stock_valuation AS
SELECT
    b.organization_id,
    cs.branch_id,
    p.product_code,
    p.product_name,
    cs.shop_quantity,
    cs.store_quantity,
    (cs.shop_quantity + cs.store_quantity) AS total_qty,
    u.conversion_factor,
    p.last_cost_price,
    COALESCE(NULLIF(p.last_cost_price, 0), lrc.cost_price, 0) AS effective_unit_cost,
    p.unit_price,
    (cs.shop_quantity / GREATEST(COALESCE(u.conversion_factor, 1), 1))
        * COALESCE(NULLIF(p.last_cost_price, 0), lrc.cost_price, 0) AS shop_cost_value,
    (cs.store_quantity / GREATEST(COALESCE(u.conversion_factor, 1), 1))
        * COALESCE(NULLIF(p.last_cost_price, 0), lrc.cost_price, 0) AS store_cost_value,
    ((cs.shop_quantity + cs.store_quantity) / GREATEST(COALESCE(u.conversion_factor, 1), 1))
        * COALESCE(NULLIF(p.last_cost_price, 0), lrc.cost_price, 0) AS cost_value,
    ((cs.shop_quantity + cs.store_quantity) / GREATEST(COALESCE(u.conversion_factor, 1), 1))
        * p.unit_price AS retail_value
FROM current_stock cs
JOIN branches b ON b.id = cs.branch_id
JOIN products p ON cs.product_code = p.product_code AND p.organization_id = b.organization_id
JOIN uoms u ON u.id = p.unit_id
LEFT JOIN (
    SELECT sr.organization_id, sr.product_code, sr.cost_price
    FROM stock_receipts sr
    INNER JOIN (
        SELECT organization_id, product_code, MAX(id) AS max_id
        FROM stock_receipts
        WHERE cost_price IS NOT NULL AND cost_price > 0
        GROUP BY organization_id, product_code
    ) latest ON latest.max_id = sr.id
) lrc ON lrc.organization_id = b.organization_id AND lrc.product_code = p.product_code
WHERE p.deleted_at IS NULL
SQL);
    }

    public function down(): void
    {
        // Prior definition restored by earlier migrations when rolled back in order.
    }
};
