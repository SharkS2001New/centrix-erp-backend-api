<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_stock_chain');
        DB::statement(<<<'SQL'
CREATE VIEW v_stock_chain AS
SELECT
    it.branch_id,
    it.product_code,
    p.product_name,
    p.unit_id,
    MIN(CASE WHEN it.transaction_type = 'PURCHASE' AND it.quantity_change > 0 THEN it.created_at END) AS first_received_at,
    MIN(CASE WHEN it.transaction_type = 'ADJUSTMENT' AND it.quantity_change > 0 THEN it.created_at END) AS first_adjustment_at,
    MIN(CASE
        WHEN it.quantity_change > 0
         AND it.transaction_type IN ('PURCHASE', 'ADJUSTMENT')
        THEN it.created_at
    END) AS first_entered_at,
    MIN(CASE WHEN it.transaction_type IN ('POS_SALE', 'MOBILE_SALE', 'BACKEND_SALE') THEN it.created_at END) AS first_sold_at,
    MAX(it.created_at) AS last_movement_at,
    SUM(CASE
        WHEN it.transaction_type = 'PURCHASE' AND it.quantity_change > 0
        THEN it.quantity_change * COALESCE(it.unit_cost, p.last_cost_price, 0)
        ELSE 0
    END) AS total_received,
    SUM(CASE
        WHEN it.transaction_type IN ('POS_SALE', 'MOBILE_SALE', 'BACKEND_SALE')
        THEN COALESCE(
            si.amount,
            ABS(it.quantity_change) * COALESCE(si.selling_price, p.unit_price, 0)
        )
        ELSE 0
    END) AS total_sold,
    COALESCE(cs.shop_quantity, 0) AS current_shop_stock,
    COALESCE(cs.store_quantity, 0) AS current_store_stock
FROM inventory_transactions it
JOIN products p ON it.product_code = p.product_code AND p.deleted_at IS NULL
LEFT JOIN sale_items si
    ON si.sale_id = it.reference_id
   AND si.product_code = it.product_code
   AND it.reference_type = 'sale'
LEFT JOIN current_stock cs
    ON cs.product_code = it.product_code
   AND cs.branch_id = it.branch_id
GROUP BY
    it.branch_id,
    it.product_code,
    p.product_name,
    p.unit_id,
    cs.shop_quantity,
    cs.store_quantity
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_stock_chain');
        DB::statement(<<<'SQL'
CREATE VIEW v_stock_chain AS
SELECT
    it.branch_id,
    it.product_code,
    p.product_name,
    p.unit_id,
    MIN(CASE WHEN it.transaction_type = 'PURCHASE' THEN it.created_at END) AS first_received_at,
    MIN(CASE WHEN it.transaction_type IN ('POS_SALE', 'MOBILE_SALE', 'BACKEND_SALE') THEN it.created_at END) AS first_sold_at,
    MAX(it.created_at) AS last_movement_at,
    SUM(CASE
        WHEN it.transaction_type = 'PURCHASE' AND it.quantity_change > 0
        THEN it.quantity_change * COALESCE(it.unit_cost, p.last_cost_price, 0)
        ELSE 0
    END) AS total_received,
    SUM(CASE
        WHEN it.transaction_type IN ('POS_SALE', 'MOBILE_SALE', 'BACKEND_SALE')
        THEN COALESCE(
            si.amount,
            ABS(it.quantity_change) * COALESCE(si.selling_price, p.unit_price, 0)
        )
        ELSE 0
    END) AS total_sold,
    COALESCE(cs.shop_quantity, 0) AS current_shop_stock,
    COALESCE(cs.store_quantity, 0) AS current_store_stock
FROM inventory_transactions it
JOIN products p ON it.product_code = p.product_code AND p.deleted_at IS NULL
LEFT JOIN sale_items si
    ON si.sale_id = it.reference_id
   AND si.product_code = it.product_code
   AND it.reference_type = 'sale'
LEFT JOIN current_stock cs
    ON cs.product_code = it.product_code
   AND cs.branch_id = it.branch_id
GROUP BY
    it.branch_id,
    it.product_code,
    p.product_name,
    p.unit_id,
    cs.shop_quantity,
    cs.store_quantity
SQL);
    }
};
