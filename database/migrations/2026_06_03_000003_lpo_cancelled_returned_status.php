<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('lpo_statuses')->where('status_code', 7)->exists();
        if (! $exists) {
            DB::table('lpo_statuses')->insert([
                'status_code' => 7,
                'status_name' => 'Cancelled – Items Returned to Supplier',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('lpo_statuses')->where('status_code', 7)->delete();
    }
};
