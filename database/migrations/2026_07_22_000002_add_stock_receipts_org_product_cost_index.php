<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speed up stock valuation / on-hand cost fallback that looks up the latest
 * receipt cost per product (correlated subquery ordered by id desc).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_receipts')) {
            return;
        }

        $indexNames = collect(Schema::getIndexes('stock_receipts'))
            ->pluck('name')
            ->map(fn ($name) => strtolower((string) $name))
            ->all();

        if (! in_array('stock_receipts_org_product_id_idx', $indexNames, true)) {
            Schema::table('stock_receipts', function (Blueprint $table) {
                $table->index(
                    ['organization_id', 'product_code', 'id'],
                    'stock_receipts_org_product_id_idx',
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_receipts')) {
            return;
        }

        $indexNames = collect(Schema::getIndexes('stock_receipts'))
            ->pluck('name')
            ->map(fn ($name) => strtolower((string) $name))
            ->all();

        if (in_array('stock_receipts_org_product_id_idx', $indexNames, true)) {
            Schema::table('stock_receipts', function (Blueprint $table) {
                $table->dropIndex('stock_receipts_org_product_id_idx');
            });
        }
    }
};
