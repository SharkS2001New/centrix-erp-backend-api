<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_attendance') && Schema::hasColumn('employee_attendance', 'source')) {
            DB::statement("ALTER TABLE employee_attendance MODIFY COLUMN source ENUM(
                'manual','clock_device','company_mobile','field_rep'
            ) NOT NULL DEFAULT 'manual'");
        }

        if (Schema::hasTable('mobile_rep_attendance_sessions')) {
            Schema::table('mobile_rep_attendance_sessions', function (Blueprint $table) {
                if (! Schema::hasColumn('mobile_rep_attendance_sessions', 'attendance_id')) {
                    $table->unsignedInteger('attendance_id')->nullable()->after('device_identifier');
                    $table->index('attendance_id', 'idx_mobile_rep_attendance_hr');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mobile_rep_attendance_sessions')) {
            Schema::table('mobile_rep_attendance_sessions', function (Blueprint $table) {
                if (Schema::hasColumn('mobile_rep_attendance_sessions', 'attendance_id')) {
                    $table->dropIndex('idx_mobile_rep_attendance_hr');
                    $table->dropColumn('attendance_id');
                }
            });
        }

        if (Schema::hasTable('employee_attendance') && Schema::hasColumn('employee_attendance', 'source')) {
            DB::table('employee_attendance')->where('source', 'field_rep')->update(['source' => 'manual']);
            DB::statement("ALTER TABLE employee_attendance MODIFY COLUMN source ENUM(
                'manual','clock_device','company_mobile'
            ) NOT NULL DEFAULT 'manual'");
        }
    }
};
