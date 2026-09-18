<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        if ($this->indexExists('customers', 'idx_customers_org_type_route_name')) {
            return;
        }

        // Mobile customer lists: scoped filter + ORDER BY customer_name LIMIT n
        // without a filesort over the full route territory.
        DB::statement(
            'CREATE INDEX idx_customers_org_type_route_name
             ON customers (organization_id, customer_type, route_id, deleted_at, customer_name)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        if ($this->indexExists('customers', 'idx_customers_org_type_route_name')) {
            DB::statement('DROP INDEX idx_customers_org_type_route_name ON customers');
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
