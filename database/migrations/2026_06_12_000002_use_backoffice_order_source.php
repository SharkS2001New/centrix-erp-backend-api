<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'order_source')) {
            DB::statement("ALTER TABLE sales MODIFY order_source ENUM('pos','mobile','backoffice','backend') NOT NULL DEFAULT 'backoffice'");
            DB::table('sales')->where('order_source', 'backend')->update(['order_source' => 'backoffice']);
        }

        if (Schema::hasTable('temporary_carts') && Schema::hasColumn('temporary_carts', 'order_source')) {
            DB::statement("ALTER TABLE temporary_carts MODIFY order_source ENUM('pos','mobile','backoffice','backend') NOT NULL DEFAULT 'backoffice'");
            DB::table('temporary_carts')->where('order_source', 'backend')->update(['order_source' => 'backoffice']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'order_source')) {
            DB::table('sales')->where('order_source', 'backoffice')->update(['order_source' => 'backend']);
            DB::statement("ALTER TABLE sales MODIFY order_source ENUM('pos','mobile','backend') NOT NULL DEFAULT 'backend'");
        }

        if (Schema::hasTable('temporary_carts') && Schema::hasColumn('temporary_carts', 'order_source')) {
            DB::table('temporary_carts')->where('order_source', 'backoffice')->update(['order_source' => 'backend']);
            DB::statement("ALTER TABLE temporary_carts MODIFY order_source ENUM('pos','mobile','backend') NOT NULL DEFAULT 'backend'");
        }
    }
};
