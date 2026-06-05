<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('lpo_statuses')
            ->where('status_code', 6)
            ->update(['status_name' => 'LPO Cleared (Payment Made)']);
    }

    public function down(): void
    {
        DB::table('lpo_statuses')
            ->where('status_code', 6)
            ->update(['status_name' => 'LPO Cleared']);
    }
};
