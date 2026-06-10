<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE sales MODIFY COLUMN status ENUM(
            'draft','held','booked','pending','unpaid','processed',
            'pending_payment','paid','delivered','completed','cancelled'
        ) NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::table('sales')->where('status', 'delivered')->update(['status' => 'processed']);

        DB::statement("ALTER TABLE sales MODIFY COLUMN status ENUM(
            'draft','held','booked','pending','unpaid','processed',
            'pending_payment','paid','completed','cancelled'
        ) NOT NULL DEFAULT 'draft'");
    }
};
