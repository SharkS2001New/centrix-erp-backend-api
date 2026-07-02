<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_take_sessions')) {
            return;
        }

        Schema::table('stock_take_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_take_sessions', 'filter_category_id')) {
                $table->unsignedInteger('filter_category_id')->nullable()->after('stock_location');
            }
            if (! Schema::hasColumn('stock_take_sessions', 'filter_subcategory_id')) {
                $table->unsignedInteger('filter_subcategory_id')->nullable()->after('filter_category_id');
            }
            if (! Schema::hasColumn('stock_take_sessions', 'filter_supplier_id')) {
                $table->unsignedInteger('filter_supplier_id')->nullable()->after('filter_subcategory_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_take_sessions')) {
            return;
        }

        Schema::table('stock_take_sessions', function (Blueprint $table) {
            foreach (['filter_supplier_id', 'filter_subcategory_id', 'filter_category_id'] as $column) {
                if (Schema::hasColumn('stock_take_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
