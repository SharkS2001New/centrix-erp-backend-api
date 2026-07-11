<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'channel')) {
            DB::statement("ALTER TABLE sales MODIFY channel ENUM('pos','mobile','backend','whatsapp') NOT NULL DEFAULT 'pos'");
        }

        if (Schema::hasTable('temporary_carts') && Schema::hasColumn('temporary_carts', 'channel')) {
            DB::statement("ALTER TABLE temporary_carts MODIFY channel ENUM('pos','mobile','backend','whatsapp') NOT NULL DEFAULT 'pos'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'channel')) {
            DB::table('sales')->where('channel', 'whatsapp')->update(['channel' => 'backend']);
            DB::statement("ALTER TABLE sales MODIFY channel ENUM('pos','mobile','backend') NOT NULL DEFAULT 'pos'");
        }

        if (Schema::hasTable('temporary_carts') && Schema::hasColumn('temporary_carts', 'channel')) {
            DB::table('temporary_carts')->where('channel', 'whatsapp')->update(['channel' => 'backend']);
            DB::statement("ALTER TABLE temporary_carts MODIFY channel ENUM('pos','mobile','backend') NOT NULL DEFAULT 'pos'");
        }
    }
};
