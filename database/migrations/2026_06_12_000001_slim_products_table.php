<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (Schema::hasColumn('products', 'updated_by')) {
            $constraints = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'products'
                   AND COLUMN_NAME = 'updated_by'
                   AND REFERENCED_TABLE_NAME IS NOT NULL"
            );
            foreach ($constraints as $row) {
                DB::statement("ALTER TABLE `products` DROP FOREIGN KEY `{$row->CONSTRAINT_NAME}`");
            }
        }

        Schema::table('products', function (Blueprint $table) {
            $drop = [
                'main_code',
                'search_name',
                'updated_online',
                'refresh_prices',
                'low_stock_alert_enabled',
                'updated_by',
            ];

            foreach ($drop as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'low_stock_alert_enabled')) {
                $table->boolean('low_stock_alert_enabled')->default(true)->after('reorder_point');
            }
            if (! Schema::hasColumn('products', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
            }
        });
    }
};
