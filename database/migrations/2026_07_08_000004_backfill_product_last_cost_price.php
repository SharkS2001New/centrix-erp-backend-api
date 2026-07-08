<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Align inventory value with physical stock for products that have qty but no last_cost.
        if (Schema::hasTable('stock_receipts')) {
            DB::statement(<<<'SQL'
UPDATE products p
INNER JOIN (
    SELECT sr.organization_id, sr.product_code, sr.cost_price
    FROM stock_receipts sr
    INNER JOIN (
        SELECT organization_id, product_code, MAX(id) AS max_id
        FROM stock_receipts
        WHERE cost_price IS NOT NULL AND cost_price > 0
        GROUP BY organization_id, product_code
    ) latest ON latest.max_id = sr.id
) src ON src.organization_id = p.organization_id AND src.product_code = p.product_code
SET p.last_cost_price = src.cost_price
WHERE p.last_cost_price IS NULL OR p.last_cost_price = 0
SQL);
        }

        if (Schema::hasTable('price_history')) {
            DB::statement(<<<'SQL'
UPDATE products p
INNER JOIN (
    SELECT ph.organization_id, ph.product_code, ph.cost_price
    FROM price_history ph
    INNER JOIN (
        SELECT organization_id, product_code, MAX(id) AS max_id
        FROM price_history
        WHERE cost_price IS NOT NULL AND cost_price > 0
        GROUP BY organization_id, product_code
    ) latest ON latest.max_id = ph.id
) src ON src.organization_id = p.organization_id AND src.product_code = p.product_code
SET p.last_cost_price = src.cost_price
WHERE p.last_cost_price IS NULL OR p.last_cost_price = 0
SQL);
        }
    }

    public function down(): void
    {
        // Data correction — no reverse.
    }
};
