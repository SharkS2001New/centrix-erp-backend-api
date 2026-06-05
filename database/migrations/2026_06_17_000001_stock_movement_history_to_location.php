<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE stock_movement_history MODIFY to_location VARCHAR(50) NOT NULL'
        );
    }

    public function down(): void
    {
        DB::table('stock_movement_history')
            ->whereNotIn('to_location', ['shop', 'store'])
            ->update(['to_location' => 'store']);

        DB::statement(
            "ALTER TABLE stock_movement_history MODIFY to_location ENUM('shop','store') NOT NULL"
        );
    }
};
