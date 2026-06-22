<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_runs')) {
            return;
        }

        DB::statement("ALTER TABLE payroll_runs MODIFY COLUMN status ENUM(
            'draft','pending_approval','approved','processed','paid','void'
        ) NOT NULL DEFAULT 'draft'");

        Schema::table('payroll_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_runs', 'approved_by')) {
                $table->unsignedInteger('approved_by')->nullable()->after('processed_by');
                $table->timestamp('approved_at')->nullable()->after('approved_by');
                $table->unsignedInteger('paid_by')->nullable()->after('approved_at');
                $table->timestamp('paid_at')->nullable()->after('paid_by');
                $table->string('payment_reference', 120)->nullable()->after('paid_at');

                $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_runs')) {
            return;
        }

        DB::table('payroll_runs')->whereIn('status', ['pending_approval', 'approved'])->update(['status' => 'draft']);

        DB::statement("ALTER TABLE payroll_runs MODIFY COLUMN status ENUM(
            'draft','processed','paid','void'
        ) NOT NULL DEFAULT 'draft'");

        Schema::table('payroll_runs', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_runs', 'approved_by')) {
                $table->dropForeign(['approved_by']);
                $table->dropForeign(['paid_by']);
                $table->dropColumn(['approved_by', 'approved_at', 'paid_by', 'paid_at', 'payment_reference']);
            }
        });
    }
};
