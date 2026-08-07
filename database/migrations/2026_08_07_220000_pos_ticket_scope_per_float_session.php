<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash Sales # (pos_order_num) resets per till float session after Z/close,
 * not only once per calendar day. Scope uniqueness by float session when set,
 * otherwise keep the previous per-cashier-per-day scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales') || ! Schema::hasColumn('sales', 'pos_order_num')) {
            return;
        }

        if ($this->indexExists('sales', 'uq_pos_daily_order_num')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropUnique('uq_pos_daily_order_num');
            });
        }

        if (! Schema::hasColumn('sales', 'pos_ticket_scope')) {
            // STORED generated column — MySQL 5.7+ / 8.x
            DB::statement("
                ALTER TABLE sales
                ADD COLUMN pos_ticket_scope VARCHAR(80)
                GENERATED ALWAYS AS (
                    IF(
                        float_session_id IS NOT NULL AND float_session_id > 0,
                        CONCAT('s:', float_session_id),
                        CONCAT(
                            'd:',
                            IFNULL(cashier_id, 0),
                            ':',
                            IFNULL(DATE_FORMAT(pos_order_date, '%Y-%m-%d'), '')
                        )
                    )
                ) STORED NULL
            ");
        }

        if (! $this->indexExists('sales', 'uq_pos_ticket_scope_num')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unique(
                    ['organization_id', 'pos_ticket_scope', 'pos_order_num'],
                    'uq_pos_ticket_scope_num',
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if ($this->indexExists('sales', 'uq_pos_ticket_scope_num')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropUnique('uq_pos_ticket_scope_num');
            });
        }

        if (Schema::hasColumn('sales', 'pos_ticket_scope')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('pos_ticket_scope');
            });
        }

        if (
            Schema::hasColumn('sales', 'pos_order_num')
            && ! $this->indexExists('sales', 'uq_pos_daily_order_num')
        ) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unique(
                    ['organization_id', 'cashier_id', 'pos_order_date', 'pos_order_num'],
                    'uq_pos_daily_order_num',
                );
            });
        }
    }

    protected function indexExists(string $table, string $name): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1',
            [$database, $table, $name],
        );

        return (bool) $row;
    }
};
