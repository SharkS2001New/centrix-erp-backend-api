<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('temporary_carts', 'order_discount')) {
            Schema::table('temporary_carts', function (Blueprint $table) {
                $table->double('order_discount')->default(0)->after('route_id');
            });
        }

        if (! Schema::hasColumn('sales', 'order_discount')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->double('order_discount')->default(0)->after('order_total');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('temporary_carts', 'order_discount')) {
            Schema::table('temporary_carts', function (Blueprint $table) {
                $table->dropColumn('order_discount');
            });
        }

        if (Schema::hasColumn('sales', 'order_discount')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('order_discount');
            });
        }
    }
};
