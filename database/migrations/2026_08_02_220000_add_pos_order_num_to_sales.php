<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily per-cashier POS ticket number (resets each calendar day).
 * Global sales.order_num (S00xx) stays the org-wide order identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'pos_order_num')) {
                $table->unsignedInteger('pos_order_num')->nullable()->after('order_num');
            }
            if (! Schema::hasColumn('sales', 'pos_order_date')) {
                $table->date('pos_order_date')->nullable()->after('pos_order_num');
            }
        });

        if (! $this->indexExists('sales', 'uq_pos_daily_order_num')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unique(
                    ['organization_id', 'cashier_id', 'pos_order_date', 'pos_order_num'],
                    'uq_pos_daily_order_num',
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if ($this->indexExists('sales', 'uq_pos_daily_order_num')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropUnique('uq_pos_daily_order_num');
            });
        }

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'pos_order_date')) {
                $table->dropColumn('pos_order_date');
            }
            if (Schema::hasColumn('sales', 'pos_order_num')) {
                $table->dropColumn('pos_order_num');
            }
        });
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
