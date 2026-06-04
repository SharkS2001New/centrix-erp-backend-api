<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_deduction_types')) {
            return;
        }

        Schema::table('payroll_deduction_types', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_deduction_types', 'applies_to_all')) {
                $table->boolean('applies_to_all')->default(false)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_deduction_types', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_deduction_types', 'applies_to_all')) {
                $table->dropColumn('applies_to_all');
            }
        });
    }
};
