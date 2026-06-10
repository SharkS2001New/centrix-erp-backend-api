<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceRouteForeignKey('customers', 'route_id');
        $this->replaceRouteForeignKey('sales', 'route_id');
        $this->replaceRouteForeignKey('temporary_carts', 'route_id');
    }

    public function down(): void
    {
        $this->replaceRouteForeignKey('customers', 'route_id', false);
        $this->replaceRouteForeignKey('sales', 'route_id', false);
        $this->replaceRouteForeignKey('temporary_carts', 'route_id', false);
    }

    protected function replaceRouteForeignKey(string $table, string $column, bool $nullOnDelete = true): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $constraints = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME = ?',
            [$table, $column, 'routes']
        );

        foreach ($constraints as $row) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$row->CONSTRAINT_NAME}`");
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $nullOnDelete) {
            $foreign = $blueprint->foreign($column)->references('id')->on('routes');
            if ($nullOnDelete) {
                $foreign->nullOnDelete();
            }
        });
    }
};
