<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('temporary_carts')) {
            return;
        }

        Schema::table('temporary_carts', function (Blueprint $table) {
            if (! Schema::hasColumn('temporary_carts', 'held_order_num')) {
                $table->unsignedInteger('held_order_num')->nullable()->after('order_discount');
            }
            if (! Schema::hasColumn('temporary_carts', 'superseded_sale_id')) {
                $table->unsignedBigInteger('superseded_sale_id')->nullable()->after('held_order_num');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('temporary_carts')) {
            return;
        }

        Schema::table('temporary_carts', function (Blueprint $table) {
            if (Schema::hasColumn('temporary_carts', 'superseded_sale_id')) {
                $table->dropColumn('superseded_sale_id');
            }
            if (Schema::hasColumn('temporary_carts', 'held_order_num')) {
                $table->dropColumn('held_order_num');
            }
        });
    }
};
