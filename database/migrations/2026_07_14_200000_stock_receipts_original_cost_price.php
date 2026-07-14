<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_receipts')) {
            return;
        }

        Schema::table('stock_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_receipts', 'original_cost_price')) {
                $table->decimal('original_cost_price', 18, 4)->nullable()->after('cost_price');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_receipts')) {
            return;
        }

        Schema::table('stock_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('stock_receipts', 'original_cost_price')) {
                $table->dropColumn('original_cost_price');
            }
        });
    }
};
