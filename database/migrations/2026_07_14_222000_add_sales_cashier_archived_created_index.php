<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if ($this->indexExists('sales', 'idx_sales_cashier_archived_created')) {
            return;
        }

        // Mobile rep order lists: cashier_id + archived + created_at window.
        DB::statement(
            'CREATE INDEX idx_sales_cashier_archived_created
             ON sales (cashier_id, archived, created_at)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if ($this->indexExists('sales', 'idx_sales_cashier_archived_created')) {
            DB::statement('DROP INDEX idx_sales_cashier_archived_created ON sales');
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
