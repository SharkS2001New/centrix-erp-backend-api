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

        $indexes = [
            'idx_sales_org_archived_id' => '(organization_id, archived, id)',
            'idx_sales_org_status_archived_completed' => '(organization_id, status, archived, completed_at)',
            'idx_sales_org_route_status' => '(organization_id, route_id, status)',
        ];

        foreach ($indexes as $name => $columns) {
            if ($this->indexExists('sales', $name)) {
                continue;
            }

            DB::statement("CREATE INDEX {$name} ON sales {$columns}");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        foreach ([
            'idx_sales_org_archived_id',
            'idx_sales_org_status_archived_completed',
            'idx_sales_org_route_status',
        ] as $name) {
            if ($this->indexExists('sales', $name)) {
                DB::statement("DROP INDEX {$name} ON sales");
            }
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
