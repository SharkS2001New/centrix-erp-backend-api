<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
    p.last_cost_price,
    p.unit_price,
    (cs.shop_quantity + cs.store_quantity) * COALESCE(p.last_cost_price, 0) AS cost_value,
    (cs.shop_quantity + cs.store_quantity) * p.unit_price AS retail_value
FROM current_stock cs
JOIN branches b ON b.id = cs.branch_id
JOIN products p ON cs.product_code = p.product_code AND p.organization_id = b.organization_id
WHERE p.deleted_at IS NULL
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_stock_valuation');
        DB::statement(<<<'SQL'
CREATE VIEW v_stock_valuation AS
SELECT
    cs.branch_id,
    p.product_code,
    p.product_name,
    cs.shop_quantity,
    cs.store_quantity,
    (cs.shop_quantity + cs.store_quantity) AS total_qty,
    p.last_cost_price,
    p.unit_price,
    (cs.shop_quantity + cs.store_quantity) * COALESCE(p.last_cost_price, 0) AS cost_value,
    (cs.shop_quantity + cs.store_quantity) * p.unit_price AS retail_value
FROM current_stock cs
JOIN products p ON cs.product_code = p.product_code
WHERE p.deleted_at IS NULL
SQL);
    }
};
