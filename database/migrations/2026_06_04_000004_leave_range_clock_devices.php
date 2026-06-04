<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_leave_days')) {
            Schema::table('employee_leave_days', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_leave_days', 'start_date')) {
                    $table->date('start_date')->nullable()->after('organization_id');
                }
                if (! Schema::hasColumn('employee_leave_days', 'end_date')) {
                    $table->date('end_date')->nullable()->after('start_date');
                }
                if (! Schema::hasColumn('employee_leave_days', 'duration_type')) {
                    $table->enum('duration_type', ['full_day', 'half_day'])->default('full_day')->after('leave_type');
                }
                if (! Schema::hasColumn('employee_leave_days', 'half_day_period')) {
                    $table->enum('half_day_period', ['morning', 'afternoon'])->nullable()->after('duration_type');
                }
                if (! Schema::hasColumn('employee_leave_days', 'total_days')) {
                    $table->decimal('total_days', 6, 2)->default(1)->after('half_day_period');
                }
                if (! Schema::hasColumn('employee_leave_days', 'total_hours')) {
                    $table->decimal('total_hours', 6, 2)->nullable()->after('total_days');
                }
            });

            if (Schema::hasColumn('employee_leave_days', 'leave_date')) {
                DB::table('employee_leave_days')
                    ->whereNull('start_date')
                    ->update([
                        'start_date' => DB::raw('leave_date'),
                        'end_date' => DB::raw('leave_date'),
                        'total_days' => 1,
                    ]);
            }

            if (Schema::hasColumn('employee_leave_days', 'leave_date')) {
                Schema::table('employee_leave_days', function (Blueprint $table) {
                    $table->dropColumn('leave_date');
                });
            }
        }

        if (! Schema::hasTable('attendance_clock_devices')) {
            Schema::create('attendance_clock_devices', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('organization_id');
                $table->string('device_no', 50);
                $table->string('location', 200)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('organization_id')->references('id')->on('organizations');
                $table->unique(['organization_id', 'device_no'], 'uq_org_clock_device');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_clock_devices');

        if (Schema::hasTable('employee_leave_days')) {
            Schema::table('employee_leave_days', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_leave_days', 'leave_date')) {
                    $table->date('leave_date')->nullable();
                }
                foreach (['total_hours', 'total_days', 'half_day_period', 'duration_type', 'end_date', 'start_date'] as $col) {
                    if (Schema::hasColumn('employee_leave_days', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
