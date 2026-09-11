<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_leave_days') || ! Schema::hasColumn('employee_leave_days', 'duration_type')) {
            return;
        }

        DB::statement("ALTER TABLE employee_leave_days MODIFY COLUMN duration_type ENUM('full_day','half_day','hourly') NOT NULL DEFAULT 'full_day'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_leave_days') || ! Schema::hasColumn('employee_leave_days', 'duration_type')) {
            return;
        }

        DB::table('employee_leave_days')->where('duration_type', 'hourly')->update(['duration_type' => 'half_day']);
        DB::statement("ALTER TABLE employee_leave_days MODIFY COLUMN duration_type ENUM('full_day','half_day') NOT NULL DEFAULT 'full_day'");
    }
};
