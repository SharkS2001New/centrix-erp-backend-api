<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if (! Schema::hasColumn('sales', 'effective_sale_date')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->date('effective_sale_date')->nullable()->after('completed_at');
            });
        }

        DB::statement(
            'UPDATE sales
             SET effective_sale_date = DATE(COALESCE(completed_at, created_at))
             WHERE effective_sale_date IS NULL',
        );

        if (! $this->indexExists('sales', 'idx_sales_org_effective_date_status')) {
            DB::statement(
                'CREATE INDEX idx_sales_org_effective_date_status
                 ON sales (organization_id, effective_sale_date, status, archived)',
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if ($this->indexExists('sales', 'idx_sales_org_effective_date_status')) {
            DB::statement('DROP INDEX idx_sales_org_effective_date_status ON sales');
        }

        if (Schema::hasColumn('sales', 'effective_sale_date')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('effective_sale_date');
            });
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
