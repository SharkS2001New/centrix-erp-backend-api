<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Amount-bypass / intentional KRA skips write status=skipped.
 * Production ENUM was only pending|success|failed → Data truncated (1265) on insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE kra_responses MODIFY COLUMN status ENUM('pending','success','failed','skipped') NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        // Rows already skipped cannot stay in the narrowed ENUM — map them to failed first.
        DB::table('kra_responses')->where('status', 'skipped')->update(['status' => 'failed']);

        DB::statement(
            "ALTER TABLE kra_responses MODIFY COLUMN status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending'"
        );
    }
};
