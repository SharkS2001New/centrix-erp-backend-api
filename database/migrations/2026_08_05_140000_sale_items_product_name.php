<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot product names onto sale lines so POS/order history still shows a name
 * when the catalogue row is later soft-deleted or purged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sale_items', 'product_name')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->string('product_name', 250)->nullable()->after('product_code');
            });
        }

        // Backfill from live + soft-deleted catalogue rows.
        if (Schema::hasTable('products') && Schema::hasColumn('sale_items', 'product_name')) {
            DB::statement(
                'UPDATE sale_items si
                 INNER JOIN products p ON p.product_code = si.product_code
                 SET si.product_name = p.product_name
                 WHERE si.product_name IS NULL OR si.product_name = \'\''
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sale_items', 'product_name')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->dropColumn('product_name');
            });
        }
    }
};
