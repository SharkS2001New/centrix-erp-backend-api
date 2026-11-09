<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * current_stock is maintained from inventory_transactions.quantity_after.
 * Schema documents trg_sync_stock; some restored DBs lost every trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_transactions') || ! Schema::hasTable('current_stock')) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_sync_stock');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_sync_stock
AFTER INSERT ON inventory_transactions
FOR EACH ROW
BEGIN
    INSERT INTO current_stock (product_code, branch_id, shop_quantity, store_quantity)
    VALUES (
        NEW.product_code,
        NEW.branch_id,
        IF(NEW.stock_location = 'shop', NEW.quantity_after, 0),
        IF(NEW.stock_location = 'store', NEW.quantity_after, 0)
    )
    ON DUPLICATE KEY UPDATE
        shop_quantity  = IF(NEW.stock_location = 'shop', NEW.quantity_after, shop_quantity),
        store_quantity = IF(NEW.stock_location = 'store', NEW.quantity_after, store_quantity),
        last_updated   = CURRENT_TIMESTAMP;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_sync_stock');
    }
};
