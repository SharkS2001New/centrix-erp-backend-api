<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'pays_nssf')) {
                $table->boolean('pays_nssf')->default(true)->after('pays_sha');
            }
            if (! Schema::hasColumn('employees', 'pays_housing_levy')) {
                $table->boolean('pays_housing_levy')->default(true)->after('pays_nssf');
            }
            if (! Schema::hasColumn('employees', 'pays_paye')) {
                $table->boolean('pays_paye')->default(true)->after('pays_housing_levy');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach (['pays_paye', 'pays_housing_levy', 'pays_nssf'] as $col) {
                if (Schema::hasColumn('employees', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
