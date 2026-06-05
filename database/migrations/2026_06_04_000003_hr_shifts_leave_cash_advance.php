<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_shifts')) {
            Schema::create('work_shifts', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('organization_id');
                $table->string('shift_code', 45);
                $table->string('shift_name', 120);
                $table->time('start_time');
                $table->time('end_time');
                $table->boolean('crosses_midnight')->default(false);
                $table->boolean('works_saturday')->default(false);
                $table->boolean('works_sunday')->default(false);
                $table->boolean('works_public_holidays')->default(false);
                $table->boolean('is_active')->default(true);

                $table->foreign('organization_id')->references('id')->on('organizations');
                $table->unique(['organization_id', 'shift_code'], 'uq_org_shift_code');
            });
        }

        if (! Schema::hasTable('organization_holidays')) {
            Schema::create('organization_holidays', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('organization_id');
                $table->date('holiday_date');
                $table->string('name', 200);
                $table->boolean('is_active')->default(true);

                $table->foreign('organization_id')->references('id')->on('organizations');
                $table->unique(['organization_id', 'holiday_date'], 'uq_org_holiday_date');
            });
        }

        if (! Schema::hasTable('employee_leave_days')) {
            Schema::create('employee_leave_days', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('employee_id');
                $table->integer('organization_id');
                $table->date('leave_date');
                $table->enum('leave_type', ['annual', 'sick', 'unpaid', 'other'])->default('annual');
                $table->string('notes', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
                $table->foreign('organization_id')->references('id')->on('organizations');
                $table->unique(['employee_id', 'leave_date'], 'uq_emp_leave_date');
            });
        }

        if (! Schema::hasColumn('employees', 'shift_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unsignedInteger('shift_id')->nullable()->after('position_id');
            });
        } else {
            // Recover from a prior failed run that created a signed INT column.
            DB::statement('ALTER TABLE `employees` MODIFY `shift_id` INT UNSIGNED NULL');
        }

        if (! $this->foreignKeyExists('employees', 'employees_shift_id_foreign')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->foreign('shift_id')->references('id')->on('work_shifts')->nullOnDelete();
            });
        }

        Schema::table('employee_cash_advances', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_cash_advances', 'repayment_mode')) {
                $table->enum('repayment_mode', ['full_next_cycle', 'fixed_per_cycle'])
                    ->default('fixed_per_cycle')
                    ->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_cash_advances', function (Blueprint $table) {
            if (Schema::hasColumn('employee_cash_advances', 'repayment_mode')) {
                $table->dropColumn('repayment_mode');
            }
        });

        if ($this->foreignKeyExists('employees', 'employees_shift_id_foreign')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropForeign(['shift_id']);
            });
        }

        if (Schema::hasColumn('employees', 'shift_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('shift_id');
            });
        }

        Schema::dropIfExists('employee_leave_days');
        Schema::dropIfExists('organization_holidays');
        Schema::dropIfExists('work_shifts');
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $rows = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [$table, $constraintName, 'FOREIGN KEY'],
        );

        return count($rows) > 0;
    }
};
