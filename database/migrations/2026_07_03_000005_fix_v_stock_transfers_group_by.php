<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_movement_history')) {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS v_stock_transfers');
        DB::statement(<<<'SQL'
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
    COUNT(*) AS transfer_count
FROM stock_movement_history smh
JOIN branches b ON smh.branch_id = b.id
JOIN products p ON smh.product_code = p.product_code
    AND p.organization_id = b.organization_id
GROUP BY DATE(smh.created_at), smh.branch_id, b.organization_id, smh.product_code, smh.from_location, smh.to_location
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_movement_history')) {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS v_stock_transfers');
        DB::statement(<<<'SQL'
CREATE VIEW v_stock_transfers AS
SELECT
    DATE(smh.created_at) AS transfer_date,
    smh.branch_id,
    smh.product_code,
    p.product_name,
    smh.from_location,
    smh.to_location,
    SUM(smh.quantity_moved) AS total_moved,
    COUNT(*) AS transfer_count
FROM stock_movement_history smh
JOIN products p ON smh.product_code = p.product_code
GROUP BY DATE(smh.created_at), smh.branch_id, smh.product_code, smh.from_location, smh.to_location
SQL);
    }
};
