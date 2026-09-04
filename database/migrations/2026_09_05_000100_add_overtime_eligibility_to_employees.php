<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'eligible_for_overtime')) {
                $table->boolean('eligible_for_overtime')->default(true)->after('pays_paye');
            }
            if (! Schema::hasColumn('employees', 'auto_approve_overtime')) {
                $table->boolean('auto_approve_overtime')->default(true)->after('eligible_for_overtime');
            }
            if (! Schema::hasColumn('employees', 'monthly_overtime_amount')) {
                $table->decimal('monthly_overtime_amount', 12, 2)->nullable()->after('auto_approve_overtime');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach (['monthly_overtime_amount', 'auto_approve_overtime', 'eligible_for_overtime'] as $col) {
                if (Schema::hasColumn('employees', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
