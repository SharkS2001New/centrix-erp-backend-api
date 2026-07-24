<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_deduction_types')
            && ! Schema::hasColumn('payroll_deduction_types', 'frequency')) {
            Schema::table('payroll_deduction_types', function (Blueprint $table) {
                $table->string('frequency', 20)->default('per_cycle')->after('applies_to_all');
            });
        }

        if (Schema::hasTable('employee_deductions')) {
            Schema::table('employee_deductions', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_deductions', 'frequency')) {
                    $table->string('frequency', 20)->default('per_cycle')->after('is_active');
                }
                if (! Schema::hasColumn('employee_deductions', 'payroll_run_id')) {
                    $table->unsignedBigInteger('payroll_run_id')->nullable()->after('frequency');
                    $table->index('payroll_run_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_deductions')) {
            Schema::table('employee_deductions', function (Blueprint $table) {
                if (Schema::hasColumn('employee_deductions', 'payroll_run_id')) {
                    $table->dropIndex(['payroll_run_id']);
                    $table->dropColumn('payroll_run_id');
                }
                if (Schema::hasColumn('employee_deductions', 'frequency')) {
                    $table->dropColumn('frequency');
                }
            });
        }

        if (Schema::hasTable('payroll_deduction_types')
            && Schema::hasColumn('payroll_deduction_types', 'frequency')) {
            Schema::table('payroll_deduction_types', function (Blueprint $table) {
                $table->dropColumn('frequency');
            });
        }
    }
};
