<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_receipts')) {
            return;
        }

        Schema::table('stock_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_receipts', 'expiry_cleared_at')) {
                $table->timestamp('expiry_cleared_at')->nullable()->after('expiry_date');
            }
            if (! Schema::hasColumn('stock_receipts', 'expiry_cleared_by')) {
                $table->unsignedBigInteger('expiry_cleared_by')->nullable()->after('expiry_cleared_at');
            }
            if (! Schema::hasColumn('stock_receipts', 'expiry_cleared_qty')) {
                $table->decimal('expiry_cleared_qty', 12, 3)->nullable()->after('expiry_cleared_by');
            }
            if (! Schema::hasColumn('stock_receipts', 'expiry_clear_reason')) {
                $table->string('expiry_clear_reason', 500)->nullable()->after('expiry_cleared_qty');
            }
            if (! Schema::hasColumn('stock_receipts', 'expiry_clear_damage_id')) {
                $table->unsignedInteger('expiry_clear_damage_id')->nullable()->after('expiry_clear_reason');
            }
        });

        if (
            Schema::hasColumn('stock_receipts', 'expiry_date')
            && ! $this->indexExists('stock_receipts', 'stock_receipts_org_expiry_idx')
        ) {
            Schema::table('stock_receipts', function (Blueprint $table) {
                $table->index(['organization_id', 'expiry_date'], 'stock_receipts_org_expiry_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_receipts')) {
            return;
        }

        if ($this->indexExists('stock_receipts', 'stock_receipts_org_expiry_idx')) {
            Schema::table('stock_receipts', function (Blueprint $table) {
                $table->dropIndex('stock_receipts_org_expiry_idx');
            });
        }

        Schema::table('stock_receipts', function (Blueprint $table) {
            foreach ([
                'expiry_clear_damage_id',
                'expiry_clear_reason',
                'expiry_cleared_qty',
                'expiry_cleared_by',
                'expiry_cleared_at',
            ] as $col) {
                if (Schema::hasColumn('stock_receipts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1',
            [$database, $table, $index],
        );

        return $row !== null;
    }
};
