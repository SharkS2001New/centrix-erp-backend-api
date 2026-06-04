<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'monthly_allowance')) {
                $table->decimal('monthly_allowance', 12, 2)->default(0)->after('base_salary');
            }
        });

        if (Schema::hasTable('employee_cash_advances') && Schema::hasColumn('employee_cash_advances', 'repayment_mode')) {
            DB::table('employee_cash_advances')
                ->whereNull('repayment_mode')
                ->where('status', 'open')
                ->where('balance', '>', 0)
                ->update(['repayment_mode' => 'full_next_cycle']);
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'monthly_allowance')) {
                $table->dropColumn('monthly_allowance');
            }
        });
    }
};
