<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_run_settlements')) {
            Schema::create('payroll_run_settlements', function (Blueprint $table) {
                $table->id();
                $table->integer('payroll_run_id');
                $table->integer('organization_id');
                $table->string('item_type', 32);
                $table->unsignedInteger('item_id');
                $table->json('snapshot');
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->cascadeOnDelete();
                $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
                $table->unique(['payroll_run_id', 'item_type', 'item_id'], 'uq_payroll_run_settlement_item');
                $table->index(['organization_id', 'item_type']);
            });
        }

        Schema::table('employee_attendance', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_attendance', 'payroll_run_id')) {
                $table->integer('payroll_run_id')->nullable()->after('notes');
                $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->nullOnDelete();
            }
        });

        Schema::table('employee_overtime', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_overtime', 'payroll_run_id')) {
                $table->integer('payroll_run_id')->nullable()->after('pay_period_id');
                $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->nullOnDelete();
            }
        });

        Schema::table('employee_leave_days', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_leave_days', 'payroll_run_id')) {
                $table->integer('payroll_run_id')->nullable()->after('notes');
                $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_leave_days', function (Blueprint $table) {
            if (Schema::hasColumn('employee_leave_days', 'payroll_run_id')) {
                $table->dropForeign(['payroll_run_id']);
                $table->dropColumn('payroll_run_id');
            }
        });

        Schema::table('employee_overtime', function (Blueprint $table) {
            if (Schema::hasColumn('employee_overtime', 'payroll_run_id')) {
                $table->dropForeign(['payroll_run_id']);
                $table->dropColumn('payroll_run_id');
            }
        });

        Schema::table('employee_attendance', function (Blueprint $table) {
            if (Schema::hasColumn('employee_attendance', 'payroll_run_id')) {
                $table->dropForeign(['payroll_run_id']);
                $table->dropColumn('payroll_run_id');
            }
        });

        Schema::dropIfExists('payroll_run_settlements');
    }
};
