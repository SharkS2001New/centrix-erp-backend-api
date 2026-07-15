<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production still had customer_returns.customer_returns_return_no_unique (global),
 * while numbering is per-organization. Re-apply the tenant-scoped unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_returns')) {
            return;
        }

        foreach (['customer_returns_return_no_unique', 'return_no'] as $index) {
            if ($this->indexExists('customer_returns', $index)) {
                DB::statement("ALTER TABLE customer_returns DROP INDEX `{$index}`");
            }
        }

        if (! $this->indexExists('customer_returns', 'uq_org_customer_return_no')) {
            Schema::table('customer_returns', function (Blueprint $table) {
                $table->unique(['organization_id', 'return_no'], 'uq_org_customer_return_no');
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
