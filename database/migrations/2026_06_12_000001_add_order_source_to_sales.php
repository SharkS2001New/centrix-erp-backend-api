<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales') && ! Schema::hasColumn('sales', 'order_source')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->enum('order_source', ['pos', 'mobile', 'backoffice', 'backend'])
                    ->default('backoffice')
                    ->after('channel');
            });

            DB::table('sales')->update([
                'order_source' => DB::raw("CASE WHEN channel = 'backend' THEN 'backoffice' ELSE channel END"),
            ]);
        }

        if (Schema::hasTable('temporary_carts') && ! Schema::hasColumn('temporary_carts', 'order_source')) {
            Schema::table('temporary_carts', function (Blueprint $table) {
                $table->enum('order_source', ['pos', 'mobile', 'backoffice', 'backend'])
                    ->default('backoffice')
                    ->after('channel');
            });

            DB::table('temporary_carts')->update([
                'order_source' => DB::raw("CASE WHEN channel = 'backend' THEN 'backoffice' ELSE channel END"),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'order_source')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('order_source');
            });
        }

        if (Schema::hasTable('temporary_carts') && Schema::hasColumn('temporary_carts', 'order_source')) {
            Schema::table('temporary_carts', function (Blueprint $table) {
                $table->dropColumn('order_source');
            });
        }
    }
};
