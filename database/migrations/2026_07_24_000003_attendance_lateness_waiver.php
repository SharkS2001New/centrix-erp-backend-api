<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_attendance', function (Blueprint $table) {
            $table->boolean('lateness_waived')->default(false)->after('late_minutes');
            $table->string('lateness_waiver_reason', 500)->nullable()->after('lateness_waived');
            $table->unsignedBigInteger('lateness_waived_by')->nullable()->after('lateness_waiver_reason');
            $table->timestamp('lateness_waived_at')->nullable()->after('lateness_waived_by');
        });
    }

    public function down(): void
    {
        Schema::table('employee_attendance', function (Blueprint $table) {
            $table->dropColumn([
                'lateness_waived',
                'lateness_waiver_reason',
                'lateness_waived_by',
                'lateness_waived_at',
            ]);
        });
    }
};
