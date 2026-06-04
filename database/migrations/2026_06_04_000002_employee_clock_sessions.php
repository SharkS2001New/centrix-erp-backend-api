<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_attendance', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_attendance', 'source')) {
                $table->enum('source', ['manual', 'clock_device'])->default('manual')->after('status');
            }
            if (! Schema::hasColumn('employee_attendance', 'device_identifier')) {
                $table->string('device_identifier', 100)->nullable()->after('source');
            }
        });

        if (! Schema::hasTable('employee_clock_sessions')) {
            Schema::create('employee_clock_sessions', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('employee_id');
                $table->integer('organization_id');
                $table->integer('branch_id')->nullable();
                $table->dateTime('clock_in_at');
                $table->dateTime('clock_out_at')->nullable();
                $table->string('device_identifier', 100)->nullable();
                $table->integer('attendance_id')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
                $table->foreign('organization_id')->references('id')->on('organizations');
                $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
                $table->foreign('attendance_id')->references('id')->on('employee_attendance')->nullOnDelete();
                $table->index(['employee_id', 'clock_out_at'], 'idx_clock_open');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_clock_sessions');

        Schema::table('employee_attendance', function (Blueprint $table) {
            if (Schema::hasColumn('employee_attendance', 'device_identifier')) {
                $table->dropColumn('device_identifier');
            }
            if (Schema::hasColumn('employee_attendance', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
