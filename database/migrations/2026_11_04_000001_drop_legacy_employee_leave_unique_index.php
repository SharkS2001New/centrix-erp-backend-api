<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_leave_days')) {
            return;
        }

        $indexes = Schema::getIndexes('employee_leave_days');
        if (! collect($indexes)->contains('name', 'uq_emp_leave_date')) {
            return;
        }

        $hasEmployeeIdIndex = collect($indexes)->contains(
            fn (array $index) => ! ($index['unique'] ?? false)
                && ($index['columns'][0] ?? null) === 'employee_id',
        );

        if (! $hasEmployeeIdIndex) {
            Schema::table('employee_leave_days', function (Blueprint $table) {
                $table->index('employee_id', 'idx_employee_leave_days_employee_id');
            });
        }

        Schema::table('employee_leave_days', function (Blueprint $table) {
            $table->dropUnique('uq_emp_leave_date');
        });
    }

    public function down(): void
    {
        // The legacy index constrained each employee to one leave row after leave_date was removed.
    }
};
