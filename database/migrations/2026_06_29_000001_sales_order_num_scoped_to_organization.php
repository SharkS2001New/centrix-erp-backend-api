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

        if ($this->indexExists('sales', 'order_num')) {
            DB::statement('ALTER TABLE sales DROP INDEX order_num');
        }

        if (! $this->indexExists('sales', 'uq_org_order_num')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unique(['organization_id', 'order_num'], 'uq_org_order_num');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if ($this->indexExists('sales', 'uq_org_order_num')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropUnique('uq_org_order_num');
            });
        }

        if (! $this->indexExists('sales', 'order_num')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unique('order_num');
            });
        }
    }

    protected function indexExists(string $table, string $index): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $index],
        );

        return (int) ($row->c ?? 0) > 0;
    }
};
