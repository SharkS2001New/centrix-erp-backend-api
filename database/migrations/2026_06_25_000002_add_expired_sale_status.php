<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE sales MODIFY COLUMN status ENUM(
            'draft','held','booked','pending','unpaid','processed',
            'pending_payment','paid','delivered','completed','cancelled','expired'
        ) NOT NULL DEFAULT 'draft'");

        if (Schema::hasTable('sales') && ! Schema::hasColumn('sales', 'expired_at')) {
            Schema::table('sales', function ($table) {
                $table->timestamp('expired_at')->nullable()->after('cancelled_by');
                $table->unsignedInteger('expired_by')->nullable()->after('expired_at');
                $table->foreign('expired_by')->references('id')->on('users');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales')) {
            DB::table('sales')->where('status', 'expired')->update(['status' => 'cancelled']);

            if (Schema::hasColumn('sales', 'expired_by')) {
                Schema::table('sales', function ($table) {
                    $table->dropForeign(['expired_by']);
                    $table->dropColumn(['expired_at', 'expired_by']);
                });
            }
        }

        DB::statement("ALTER TABLE sales MODIFY COLUMN status ENUM(
            'draft','held','booked','pending','unpaid','processed',
            'pending_payment','paid','delivered','completed','cancelled'
        ) NOT NULL DEFAULT 'draft'");
    }
};
