<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->unsignedSmallInteger('lunch_minutes')->default(60)->after('end_time');
            $table->unsignedSmallInteger('alternate_lunch_minutes')->nullable()->after('alternate_end_time');
            $table->boolean('lunch_required')->default(true)->after('lunch_minutes');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('bank_lunch_as_work')->default(false)->after('work_weekdays');
        });

        Schema::table('employee_attendance', function (Blueprint $table) {
            $table->decimal('expected_hours', 5, 2)->nullable()->after('hours_worked');
            $table->unsignedSmallInteger('late_minutes')->default(0)->after('expected_hours');
            $table->string('lunch_status', 16)->nullable()->after('late_minutes');
            $table->unsignedSmallInteger('lunch_minutes')->nullable()->after('lunch_status');
            $table->unsignedSmallInteger('early_leave_minutes')->default(0)->after('lunch_minutes');
            $table->unsignedSmallInteger('overtime_minutes')->default(0)->after('early_leave_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('employee_attendance', function (Blueprint $table) {
            $table->dropColumn([
                'expected_hours',
                'late_minutes',
                'lunch_status',
                'lunch_minutes',
                'early_leave_minutes',
                'overtime_minutes',
            ]);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('bank_lunch_as_work');
        });

        Schema::table('work_shifts', function (Blueprint $table) {
            $table->dropColumn([
                'lunch_minutes',
                'alternate_lunch_minutes',
                'lunch_required',
            ]);
        });
    }
};
