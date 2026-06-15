<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('till_float_sessions', function (Blueprint $table) {
            $table->json('cash_movements')->nullable()->after('float_breakdown');
            $table->json('closing_denominations')->nullable()->after('closing_amount');
        });
    }

    public function down(): void
    {
        Schema::table('till_float_sessions', function (Blueprint $table) {
            $table->dropColumn(['cash_movements', 'closing_denominations']);
        });
    }
};
