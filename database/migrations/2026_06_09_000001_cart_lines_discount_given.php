<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cart_lines')) {
            return;
        }

        if (! Schema::hasColumn('cart_lines', 'discount_given')) {
            Schema::table('cart_lines', function (Blueprint $table) {
                $table->double('discount_given')->default(0)->after('amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cart_lines', 'discount_given')) {
            Schema::table('cart_lines', function (Blueprint $table) {
                $table->dropColumn('discount_given');
            });
        }
    }
};
