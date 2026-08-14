<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_attendance') && Schema::hasColumn('employee_attendance', 'source')) {
            DB::statement("ALTER TABLE employee_attendance MODIFY COLUMN source ENUM(
                'manual','clock_device','company_mobile','field_rep','hr_applied'
            ) NOT NULL DEFAULT 'manual'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_attendance') && Schema::hasColumn('employee_attendance', 'source')) {
            DB::table('employee_attendance')->where('source', 'hr_applied')->update(['source' => 'manual']);
            DB::statement("ALTER TABLE employee_attendance MODIFY COLUMN source ENUM(
                'manual','clock_device','company_mobile','field_rep'
            ) NOT NULL DEFAULT 'manual'");
        }
    }
};
