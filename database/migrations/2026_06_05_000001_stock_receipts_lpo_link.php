<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_receipts', 'lpo_no')) {
                $table->unsignedBigInteger('lpo_no')->nullable()->after('organization_id');
            }
            if (! Schema::hasColumn('stock_receipts', 'lpo_txn_id')) {
                $table->unsignedBigInteger('lpo_txn_id')->nullable()->after('lpo_no');
            }
        });

        if (Schema::hasColumn('stock_receipts', 'lpo_no')
            && Schema::hasColumn('stock_receipts', 'lpo_txn_id')) {
            $indexExists = collect(DB::select('SHOW INDEX FROM stock_receipts'))
                ->contains(fn ($row) => ($row->Key_name ?? '') === 'idx_stock_receipts_lpo_line');
            if (! $indexExists) {
                Schema::table('stock_receipts', function (Blueprint $table) {
                    $table->index(['lpo_no', 'lpo_txn_id'], 'idx_stock_receipts_lpo_line');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('stock_receipts', function (Blueprint $table) {
            $table->dropIndex('idx_stock_receipts_lpo_line');
            if (Schema::hasColumn('stock_receipts', 'lpo_txn_id')) {
                $table->dropColumn('lpo_txn_id');
            }
            if (Schema::hasColumn('stock_receipts', 'lpo_no')) {
                $table->dropColumn('lpo_no');
            }
        });
    }
};
