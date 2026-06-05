<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Legacy columns that must not exist on products. */
    private const DROP_COLUMNS = [
        'main_code',
        'search_name',
        'updated_online',
        'refresh_prices',
        'low_stock_alert_enabled',
        'updated_by',
    ];

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
            $existing = array_filter(
                self::DROP_COLUMNS,
                fn (string $col) => Schema::hasColumn('products', $col),
            );
            if ($existing !== []) {
                $table->dropColumn(array_values($existing));
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
                $table->unsignedInteger('updated_by')->nullable()->after('created_by');
            }
        });
    }
};
