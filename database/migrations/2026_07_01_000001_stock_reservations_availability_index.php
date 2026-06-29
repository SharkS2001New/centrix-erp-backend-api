<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_reservations')) {
            return;
        }

        if ($this->indexExists('stock_reservations', 'idx_stock_reservations_availability')) {
            return;
        }

        DB::statement(
            'CREATE INDEX idx_stock_reservations_availability
             ON stock_reservations (branch_id, product_code, stock_location, released_at, expires_at)',
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_reservations')) {
            return;
        }

        if ($this->indexExists('stock_reservations', 'idx_stock_reservations_availability')) {
            DB::statement('DROP INDEX idx_stock_reservations_availability ON stock_reservations');
        }
    }

    protected function indexExists(string $table, string $index): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND index_name = ?
             LIMIT 1',
            [$table, $index],
        );

        return $rows !== [];
    }
};
