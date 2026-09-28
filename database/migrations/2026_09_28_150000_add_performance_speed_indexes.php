<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Speed upgrade Stage 5 — composite indexes for hot report / movement paths.
 *
 * - inventory_transactions (organization_id, created_at): movement reports + org-dated scans
 * - sale_items (product_code, sale_id): product→sale joins in dashboard COGS / product reports
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_transactions')
            && ! $this->indexExists('inventory_transactions', 'idx_inv_txn_org_created')
        ) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->index(['organization_id', 'created_at'], 'idx_inv_txn_org_created');
            });
        }

        if (Schema::hasTable('sale_items')
            && ! $this->indexExists('sale_items', 'idx_sale_items_product_sale')
        ) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->index(['product_code', 'sale_id'], 'idx_sale_items_product_sale');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_transactions')
            && $this->indexExists('inventory_transactions', 'idx_inv_txn_org_created')
        ) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->dropIndex('idx_inv_txn_org_created');
            });
        }

        if (Schema::hasTable('sale_items')
            && $this->indexExists('sale_items', 'idx_sale_items_product_sale')
        ) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->dropIndex('idx_sale_items_product_sale');
            });
        }
    }

    protected function indexExists(string $table, string $indexName): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1',
            [$database, $table, $indexName],
        );

        return $rows !== [];
    }
};
