<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_attendance')) {
            return;
        }
        if (Schema::hasColumn('employee_attendance', 'lunch_late_minutes')) {
            return;
        }

        Schema::table('employee_attendance', function (Blueprint $table) {
            $table->unsignedSmallInteger('lunch_late_minutes')->default(0)->after('late_minutes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_attendance')) {
            return;
        }
        if (! Schema::hasColumn('employee_attendance', 'lunch_late_minutes')) {
            return;
        }

        Schema::table('employee_attendance', function (Blueprint $table) {
            $table->dropColumn('lunch_late_minutes');
        });
    }
};
