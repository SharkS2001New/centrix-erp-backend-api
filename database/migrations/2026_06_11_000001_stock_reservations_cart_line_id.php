<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_reservations') || ! Schema::hasTable('cart_lines')) {
            return;
        }

        if (! Schema::hasColumn('stock_reservations', 'cart_line_id')) {
            Schema::table('stock_reservations', function (Blueprint $table) {
                $table->integer('cart_line_id')->nullable()->after('cart_id');
            });
        } else {
            // Match cart_lines.id (signed INT) — unsigned breaks the FK on MySQL 8.
            DB::statement('ALTER TABLE stock_reservations MODIFY cart_line_id INT NULL');
        }

        if (! $this->cartLineForeignKeyExists()) {
            Schema::table('stock_reservations', function (Blueprint $table) {
                $table->foreign('cart_line_id')
                    ->references('id')
                    ->on('cart_lines')
                    ->nullOnDelete();
            });
        }

        if (! $this->cartLineIndexExists()) {
            Schema::table('stock_reservations', function (Blueprint $table) {
                $table->index('cart_line_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_reservations') || ! Schema::hasColumn('stock_reservations', 'cart_line_id')) {
            return;
        }

        if ($this->cartLineForeignKeyExists()) {
            Schema::table('stock_reservations', function (Blueprint $table) {
                $table->dropForeign(['cart_line_id']);
            });
        }

        if ($this->cartLineIndexExists()) {
            Schema::table('stock_reservations', function (Blueprint $table) {
                $table->dropIndex(['cart_line_id']);
            });
        }

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropColumn('cart_line_id');
        });
    }

    protected function cartLineForeignKeyExists(): bool
    {
        $rows = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            ['stock_reservations', 'cart_line_id']
        );

        return count($rows) > 0;
    }

    protected function cartLineIndexExists(): bool
    {
        $rows = DB::select(
            'SHOW INDEX FROM stock_reservations WHERE Column_name = ?',
            ['cart_line_id']
        );

        return count($rows) > 0;
    }
};
