<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('temporary_carts') && ! Schema::hasColumn('temporary_carts', 'discount_voucher_id')) {
            Schema::table('temporary_carts', function (Blueprint $table) {
                $table->unsignedBigInteger('discount_voucher_id')->nullable()->after('order_discount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('temporary_carts') && Schema::hasColumn('temporary_carts', 'discount_voucher_id')) {
            Schema::table('temporary_carts', function (Blueprint $table) {
                $table->dropColumn('discount_voucher_id');
            });
        }
    }
};
