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

        if ($this->indexExists('customers', 'idx_customers_org_type_route')) {
            return;
        }

        // Mobile customer lists: org + route customer type + optional route filter.
        DB::statement(
            'CREATE INDEX idx_customers_org_type_route
             ON customers (organization_id, customer_type, route_id, deleted_at)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        if ($this->indexExists('customers', 'idx_customers_org_type_route')) {
            DB::statement('DROP INDEX idx_customers_org_type_route ON customers');
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
