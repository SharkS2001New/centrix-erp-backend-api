<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_leave_settings')) {
            Schema::create('organization_leave_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('organization_id')->unique();
                $table->decimal('annual_leave_days', 5, 2)->default(21);
                $table->decimal('monthly_accrual_days', 5, 2)->default(1.75);
                $table->unsignedTinyInteger('months_for_full_annual')->default(12);
                $table->decimal('sick_leave_days', 5, 2)->default(14);
                $table->decimal('sick_leave_full_pay_days', 5, 2)->default(7);
                $table->decimal('sick_leave_half_pay_days', 5, 2)->default(7);
                $table->unsignedTinyInteger('months_before_sick_eligibility')->default(2);
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

                $table->foreign('organization_id')->references('id')->on('organizations');
            });
        }

        if (! Schema::hasTable('employee_leave_balances')) {
            Schema::create('employee_leave_balances', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('employee_id')->unique();
                $table->integer('organization_id');
                $table->decimal('off_days_allocated', 6, 2)->default(0);
                $table->decimal('annual_adjustment', 6, 2)->default(0);
                $table->decimal('sick_adjustment', 6, 2)->default(0);
                $table->string('notes', 500)->nullable();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
                $table->foreign('organization_id')->references('id')->on('organizations');
            });
        }

        Schema::table('employee_leave_days', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_leave_days', 'assignment_kind')) {
                $table->enum('assignment_kind', ['leave', 'off_day'])->default('leave')->after('leave_type');
            }
            if (! Schema::hasColumn('employee_leave_days', 'deduct_from')) {
                $table->enum('deduct_from', ['annual', 'sick', 'off_days', 'unpaid'])->default('annual')->after('assignment_kind');
            }
            if (! Schema::hasColumn('employee_leave_days', 'days_deducted')) {
                $table->decimal('days_deducted', 6, 2)->default(0)->after('total_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_leave_days', function (Blueprint $table) {
            foreach (['days_deducted', 'deduct_from', 'assignment_kind'] as $col) {
                if (Schema::hasColumn('employee_leave_days', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::dropIfExists('employee_leave_balances');
        Schema::dropIfExists('organization_leave_settings');
    }
};
