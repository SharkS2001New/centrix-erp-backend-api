<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicles') && ! Schema::hasColumn('vehicles', 'max_weight_kg')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->decimal('max_weight_kg', 10, 2)->nullable()->after('plate_number');
                $table->decimal('max_volume_m3', 10, 2)->nullable()->after('max_weight_kg');
            });
        }

        if (Schema::hasTable('dispatch_trips')) {
            Schema::table('dispatch_trips', function (Blueprint $table) {
                if (! Schema::hasColumn('dispatch_trips', 'expected_cash')) {
                    $table->decimal('expected_cash', 14, 2)->nullable()->after('notes');
                }
                if (! Schema::hasColumn('dispatch_trips', 'collected_cash')) {
                    $table->decimal('collected_cash', 14, 2)->nullable()->after('expected_cash');
                }
                if (! Schema::hasColumn('dispatch_trips', 'cash_variance')) {
                    $table->decimal('cash_variance', 14, 2)->nullable()->after('collected_cash');
                }
                if (! Schema::hasColumn('dispatch_trips', 'settled_at')) {
                    $table->timestamp('settled_at')->nullable()->after('completed_at');
                }
                if (! Schema::hasColumn('dispatch_trips', 'settled_by')) {
                    $table->integer('settled_by')->nullable()->after('settled_at');
                    $table->foreign('settled_by')->references('id')->on('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('dispatch_trips', 'stock_deducted_at')) {
                    $table->timestamp('stock_deducted_at')->nullable()->after('settled_by');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dispatch_trips')) {
            Schema::table('dispatch_trips', function (Blueprint $table) {
                foreach (['stock_deducted_at', 'settled_by', 'settled_at', 'cash_variance', 'collected_cash', 'expected_cash'] as $col) {
                    if (Schema::hasColumn('dispatch_trips', $col)) {
                        if ($col === 'settled_by') {
                            $table->dropForeign(['settled_by']);
                        }
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('vehicles')) {
            Schema::table('vehicles', function (Blueprint $table) {
                foreach (['max_volume_m3', 'max_weight_kg'] as $col) {
                    if (Schema::hasColumn('vehicles', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
