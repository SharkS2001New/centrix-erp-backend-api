<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE sales MODIFY COLUMN status ENUM(
            'draft','held','booked','pending','unpaid','processed',
            'pending_payment','paid','delivered','completed','cancelled','expired',
            'pending_approval','editable'
        ) NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::table('sales')->whereIn('status', ['pending_approval', 'editable'])->update(['status' => 'booked']);

        DB::statement("ALTER TABLE sales MODIFY COLUMN status ENUM(
            'draft','held','booked','pending','unpaid','processed',
            'pending_payment','paid','delivered','completed','cancelled','expired'
        ) NOT NULL DEFAULT 'draft'");
    }
};
