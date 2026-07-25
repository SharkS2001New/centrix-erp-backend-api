<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash advances use status=pending for manager approval before payroll (open).
 * Live schema still had ENUM('open','repaid','cancelled') which rejected pending inserts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_cash_advances')) {
            return;
        }

        DB::statement("ALTER TABLE employee_cash_advances MODIFY COLUMN status ENUM('pending','open','repaid','cancelled') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_cash_advances')) {
            return;
        }

        // Move any pending rows to open before shrinking the ENUM.
        DB::table('employee_cash_advances')
            ->where('status', 'pending')
            ->update(['status' => 'open']);

        DB::statement("ALTER TABLE employee_cash_advances MODIFY COLUMN status ENUM('open','repaid','cancelled') NOT NULL DEFAULT 'open'");
    }
};
