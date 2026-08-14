<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_clock_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_clock_sessions', 'clock_out_kind')) {
                $table->string('clock_out_kind', 32)->nullable()->after('clock_out_at');
            }
            if (! Schema::hasColumn('employee_clock_sessions', 'needs_reconciliation')) {
                $table->boolean('needs_reconciliation')->default(false)->after('clock_out_kind');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_clock_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('employee_clock_sessions', 'needs_reconciliation')) {
                $table->dropColumn('needs_reconciliation');
            }
            if (Schema::hasColumn('employee_clock_sessions', 'clock_out_kind')) {
                $table->dropColumn('clock_out_kind');
            }
        });
    }
};
