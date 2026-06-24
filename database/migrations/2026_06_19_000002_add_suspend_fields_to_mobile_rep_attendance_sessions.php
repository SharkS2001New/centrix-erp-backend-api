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
            if (! Schema::hasColumn('mobile_rep_attendance_sessions', 'suspended_at')) {
                $table->dateTime('suspended_at')->nullable()->after('sign_out_at');
            }
            if (! Schema::hasColumn('mobile_rep_attendance_sessions', 'last_resumed_at')) {
                $table->dateTime('last_resumed_at')->nullable()->after('suspended_at');
            }
            if (! Schema::hasColumn('mobile_rep_attendance_sessions', 'accumulated_work_seconds')) {
                $table->unsignedInteger('accumulated_work_seconds')->default(0)->after('last_resumed_at');
            }
            if (! Schema::hasColumn('mobile_rep_attendance_sessions', 'close_reason')) {
                $table->string('close_reason', 50)->nullable()->after('accumulated_work_seconds');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mobile_rep_attendance_sessions')) {
            return;
        }

        Schema::table('mobile_rep_attendance_sessions', function (Blueprint $table) {
            $columns = ['suspended_at', 'last_resumed_at', 'accumulated_work_seconds', 'close_reason'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('mobile_rep_attendance_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
