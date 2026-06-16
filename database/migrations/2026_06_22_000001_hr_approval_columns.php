<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_leave_days') && ! Schema::hasColumn('employee_leave_days', 'approval_status')) {
            Schema::table('employee_leave_days', function (Blueprint $table) {
                $table->string('approval_status', 20)->default('approved')->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_leave_days') && Schema::hasColumn('employee_leave_days', 'approval_status')) {
            Schema::table('employee_leave_days', function (Blueprint $table) {
                $table->dropColumn('approval_status');
            });
        }
    }
};
