<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_overtime')) {
            return;
        }

        Schema::table('employee_overtime', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_overtime', 'rate_mode')) {
                $table->string('rate_mode', 20)->default('from_salary')->after('hours');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_overtime')) {
            return;
        }

        Schema::table('employee_overtime', function (Blueprint $table) {
            if (Schema::hasColumn('employee_overtime', 'rate_mode')) {
                $table->dropColumn('rate_mode');
            }
        });
    }
};
