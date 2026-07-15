<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production still had credit_notes.credit_notes_credit_note_no_unique (global),
 * while numbering is per-organization. Re-apply the tenant-scoped unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('credit_notes')) {
            return;
        }

        foreach (['credit_notes_credit_note_no_unique', 'credit_note_no'] as $index) {
            if ($this->indexExists('credit_notes', $index)) {
                DB::statement("ALTER TABLE credit_notes DROP INDEX `{$index}`");
            }
        }

        if (! $this->indexExists('credit_notes', 'uq_org_credit_note_no')) {
            Schema::table('credit_notes', function (Blueprint $table) {
                $table->unique(['organization_id', 'credit_note_no'], 'uq_org_credit_note_no');
            });
        }
    }

    public function down(): void
    {
        // Keep tenant-scoped uniqueness; do not restore the broken global unique.
    }

    protected function indexExists(string $table, string $index): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $index],
        );

        return (int) ($row->c ?? 0) > 0;
    }
};
