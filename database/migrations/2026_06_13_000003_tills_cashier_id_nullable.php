<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tills') || ! Schema::hasColumn('tills', 'cashier_id')) {
            return;
        }

        foreach ($this->cashierForeignKeys() as $name) {
            Schema::table('tills', function (Blueprint $table) use ($name) {
                $table->dropForeign($name);
            });
        }

        DB::statement('ALTER TABLE tills MODIFY cashier_id INT NULL');

        Schema::table('tills', function (Blueprint $table) {
            $table->foreign('cashier_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tills') || ! Schema::hasColumn('tills', 'cashier_id')) {
            return;
        }

        foreach ($this->cashierForeignKeys() as $name) {
            Schema::table('tills', function (Blueprint $table) use ($name) {
                $table->dropForeign($name);
            });
        }

        DB::statement('ALTER TABLE tills MODIFY cashier_id INT NOT NULL');

        Schema::table('tills', function (Blueprint $table) {
            $table->foreign('cashier_id')->references('id')->on('users');
        });
    }

    /** @return list<string> */
    private function cashierForeignKeys(): array
    {
        $rows = DB::select(
            "SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tills'
               AND COLUMN_NAME = 'cashier_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL"
        );

        return array_map(static fn ($row) => $row->name, $rows);
    }
};
