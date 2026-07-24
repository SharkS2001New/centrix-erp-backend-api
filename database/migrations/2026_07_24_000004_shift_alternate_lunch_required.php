<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            // null = inherit weekday lunch_required on Sat/Sun/holidays
            $table->boolean('alternate_lunch_required')->nullable()->after('alternate_lunch_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->dropColumn('alternate_lunch_required');
        });
    }
};
