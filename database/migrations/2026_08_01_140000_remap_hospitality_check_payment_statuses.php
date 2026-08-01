<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align hospitality check statuses with payment workflow:
 * open (draft) | unpaid | partially_paid | paid | void
 * Legacy: held → unpaid, settled → paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hospitality_checks')) {
            return;
        }

        DB::table('hospitality_checks')->where('status', 'held')->update(['status' => 'unpaid']);
        DB::table('hospitality_checks')->where('status', 'settled')->update(['status' => 'paid']);
        DB::table('hospitality_checks')->where('status', 'posted_to_folio')->update(['status' => 'paid']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('hospitality_checks')) {
            return;
        }

        DB::table('hospitality_checks')->where('status', 'unpaid')->update(['status' => 'held']);
        DB::table('hospitality_checks')
            ->where('status', 'paid')
            ->where('amount_paid', '>', 0)
            ->update(['status' => 'settled']);
        DB::table('hospitality_checks')->where('status', 'partially_paid')->update(['status' => 'held']);
    }
};
