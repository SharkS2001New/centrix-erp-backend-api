<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_take_lines')) {
            return;
        }

        Schema::table('stock_take_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_take_lines', 'is_counted')) {
                $table->boolean('is_counted')->default(false)->after('counted_quantity');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_take_lines')) {
            return;
        }

        Schema::table('stock_take_lines', function (Blueprint $table) {
            if (Schema::hasColumn('stock_take_lines', 'is_counted')) {
                $table->dropColumn('is_counted');
            }
        });
    }
};
