<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function foreignKeyExists(string $table, string $constraint): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $row = Schema::getConnection()->selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ? AND CONSTRAINT_NAME = ?',
            [$database, $table, 'FOREIGN KEY', $constraint],
        );

        return $row !== null;
    }

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

        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'shift_id')) {
                $table->unsignedInteger('shift_id')->nullable()->after('position_id');
            }
        });

        if (Schema::hasColumn('employees', 'shift_id')) {
            // work_shifts.id is UNSIGNED (increments); align shift_id after a partial failed run.
            Schema::getConnection()->statement(
                'ALTER TABLE `employees` MODIFY `shift_id` INT UNSIGNED NULL',
            );
        }

        // FK added separately so a partial run can be retried.
        if (
            Schema::hasColumn('employees', 'shift_id')
            && ! $this->foreignKeyExists('employees', 'employees_shift_id_foreign')
        ) {
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

        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'shift_id')) {
                $table->dropForeign(['shift_id']);
                $table->dropColumn('shift_id');
            }
        });

        Schema::dropIfExists('employee_leave_days');
        Schema::dropIfExists('organization_holidays');
        Schema::dropIfExists('work_shifts');
    }
};
