<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bar vs Hotel POS menu channels + cashier outlet assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'sell_on_bar')) {
                $table->boolean('sell_on_bar')->default(true)->after('sell_on_retail');
            }
            if (! Schema::hasColumn('products', 'sell_on_hotel')) {
                $table->boolean('sell_on_hotel')->default(true)->after('sell_on_bar');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'hospitality_outlet_id')) {
                $table->unsignedBigInteger('hospitality_outlet_id')->nullable()->after('branch_id');
                $table->foreign('hospitality_outlet_id', 'users_hosp_outlet_fk')
                    ->references('id')
                    ->on('hospitality_outlets')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'hospitality_outlet_id')) {
                $table->dropForeign('users_hosp_outlet_fk');
                $table->dropColumn('hospitality_outlet_id');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'sell_on_hotel')) {
                $table->dropColumn('sell_on_hotel');
            }
            if (Schema::hasColumn('products', 'sell_on_bar')) {
                $table->dropColumn('sell_on_bar');
            }
        });
    }
};
