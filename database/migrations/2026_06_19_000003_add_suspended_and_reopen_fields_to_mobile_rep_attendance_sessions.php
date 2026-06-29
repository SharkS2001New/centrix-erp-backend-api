<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mobile_rep_attendance_sessions')) {
            return;
        }

        Schema::table('mobile_rep_attendance_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('mobile_rep_attendance_sessions', 'accumulated_suspended_seconds')) {
                $table->unsignedInteger('accumulated_suspended_seconds')->default(0)->after('accumulated_work_seconds');
            }

            if (! Schema::hasColumn('mobile_rep_attendance_sessions', 'reopened_at')) {
                $table->timestamp('reopened_at')->nullable()->after('close_reason');
            }

            if (! Schema::hasColumn('mobile_rep_attendance_sessions', 'reopened_by_user_id')) {
                $table->unsignedBigInteger('reopened_by_user_id')->nullable()->after('reopened_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mobile_rep_attendance_sessions')) {
            return;
        }

        Schema::table('mobile_rep_attendance_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('mobile_rep_attendance_sessions', 'reopened_by_user_id')) {
                $table->dropColumn('reopened_by_user_id');
            }

            if (Schema::hasColumn('mobile_rep_attendance_sessions', 'reopened_at')) {
                $table->dropColumn('reopened_at');
            }

            if (Schema::hasColumn('mobile_rep_attendance_sessions', 'accumulated_suspended_seconds')) {
                $table->dropColumn('accumulated_suspended_seconds');
            }
        });
    }
};
