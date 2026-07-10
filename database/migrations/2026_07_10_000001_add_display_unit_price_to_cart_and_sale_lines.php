<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cart_lines') && ! Schema::hasColumn('cart_lines', 'display_unit_price')) {
            Schema::table('cart_lines', function (Blueprint $table) {
                $table->decimal('display_unit_price', 18, 4)->nullable()->after('unit_price');
            });
        }

        if (Schema::hasTable('sale_items') && ! Schema::hasColumn('sale_items', 'display_unit_price')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->decimal('display_unit_price', 18, 4)->nullable()->after('selling_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cart_lines') && Schema::hasColumn('cart_lines', 'display_unit_price')) {
            Schema::table('cart_lines', function (Blueprint $table) {
                $table->dropColumn('display_unit_price');
            });
        }

        if (Schema::hasTable('sale_items') && Schema::hasColumn('sale_items', 'display_unit_price')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->dropColumn('display_unit_price');
            });
        }
    }
};
