<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-sync denormalized organization_id from branch_id where values diverged.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'expenses',
        'damages',
        'returns',
        'supplier_returns',
        'inventory_transactions',
        'stock_reservations',
        'stock_movement_history',
        'pod_records',
        'loading_lists',
        'picking_lists',
        'dispatch_trips',
        'temporary_carts',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('branches')) {
            return;
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)
                || ! Schema::hasColumn($table, 'organization_id')
                || ! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            DB::statement("
                UPDATE {$table} t
                INNER JOIN branches b ON b.id = t.branch_id
                SET t.organization_id = b.organization_id
                WHERE t.branch_id IS NOT NULL
                  AND (t.organization_id IS NULL OR t.organization_id <> b.organization_id)
            ");
        }
    }

    public function down(): void
    {
        // Data repair — no rollback.
    }
};
