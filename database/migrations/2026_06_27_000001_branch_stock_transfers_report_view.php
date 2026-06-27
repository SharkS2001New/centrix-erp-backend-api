<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_stock_transfers')) {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS v_branch_stock_transfers');
        DB::statement(<<<'SQL'
CREATE VIEW v_branch_stock_transfers AS
SELECT
    DATE(bst.created_at) AS transfer_date,
    bst.id AS transfer_id,
    bst.organization_id,
    bst.from_branch_id,
    fb.branch_name AS from_branch_name,
    fb.branch_code AS from_branch_code,
    bst.to_branch_id,
    tb.branch_name AS to_branch_name,
    tb.branch_code AS to_branch_code,
    bst.product_code,
    p.product_name,
    bst.quantity,
    bst.from_location,
    bst.to_location,
    bst.notes,
    bst.created_by,
    u.username AS created_by_username,
    bst.created_at
FROM branch_stock_transfers bst
JOIN products p ON bst.product_code COLLATE utf8mb4_unicode_ci = p.product_code
JOIN branches fb ON bst.from_branch_id = fb.id
JOIN branches tb ON bst.to_branch_id = tb.id
JOIN users u ON bst.created_by = u.id
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_branch_stock_transfers');
    }
};
