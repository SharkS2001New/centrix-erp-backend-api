<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->boolean('use_alternate_hours')->default(false)->after('works_public_holidays');
            $table->time('alternate_start_time')->nullable()->after('use_alternate_hours');
            $table->time('alternate_end_time')->nullable()->after('alternate_start_time');
            $table->boolean('alternate_crosses_midnight')->default(false)->after('alternate_end_time');
        });

        Schema::table('employees', function (Blueprint $table) {
            // JSON array of Carbon dayOfWeek ints (0=Sun … 6=Sat). Null = default schedule via shift.
            $table->json('work_weekdays')->nullable()->after('shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->dropColumn([
                'use_alternate_hours',
                'alternate_start_time',
                'alternate_end_time',
                'alternate_crosses_midnight',
            ]);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('work_weekdays');
        });
    }
};
