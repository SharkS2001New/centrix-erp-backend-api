<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_clock_sessions')) {
            Schema::table('employee_clock_sessions', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_clock_sessions', 'clock_in_verification_method')) {
                    $table->string('clock_in_verification_method', 32)->nullable()->after('clock_in_geofence_distance_metres');
                }
                if (! Schema::hasColumn('employee_clock_sessions', 'clock_out_verification_method')) {
                    $table->string('clock_out_verification_method', 32)->nullable()->after('clock_out_geofence_distance_metres');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_clock_sessions')) {
            Schema::table('employee_clock_sessions', function (Blueprint $table) {
                foreach (['clock_in_verification_method', 'clock_out_verification_method'] as $column) {
                    if (Schema::hasColumn('employee_clock_sessions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
