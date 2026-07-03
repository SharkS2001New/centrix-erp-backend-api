<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mobile_driver_attendance_sessions')) {
            return;
        }

        Schema::table('mobile_driver_attendance_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('mobile_driver_attendance_sessions', 'reopened_at')) {
                $table->dateTime('reopened_at')->nullable()->after('close_reason');
            }
            if (! Schema::hasColumn('mobile_driver_attendance_sessions', 'reopened_by_user_id')) {
                $table->integer('reopened_by_user_id')->nullable()->after('reopened_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mobile_driver_attendance_sessions')) {
            return;
        }

        Schema::table('mobile_driver_attendance_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('mobile_driver_attendance_sessions', 'reopened_by_user_id')) {
                $table->dropColumn('reopened_by_user_id');
            }
            if (Schema::hasColumn('mobile_driver_attendance_sessions', 'reopened_at')) {
                $table->dropColumn('reopened_at');
            }
        });
    }
};
