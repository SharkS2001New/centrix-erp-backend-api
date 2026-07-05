<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_returns')) {
            return;
        }

        if (! Schema::hasColumn('customer_returns', 'return_seq')) {
            Schema::table('customer_returns', function (Blueprint $table) {
                $table->unsignedInteger('return_seq')->nullable()->after('organization_id');
            });
        }

        $organizationIds = DB::table('customer_returns')
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id');

        foreach ($organizationIds as $organizationId) {
            $ids = DB::table('customer_returns')
                ->where('organization_id', $organizationId)
                ->orderBy('id')
                ->pluck('id');

            $seq = 1;
            foreach ($ids as $id) {
                DB::table('customer_returns')
                    ->where('id', $id)
                    ->update(['return_seq' => $seq++]);
            }
        }

        DB::statement('ALTER TABLE customer_returns MODIFY return_seq INT UNSIGNED NOT NULL');

        if (! $this->indexExists('customer_returns', 'uq_org_customer_return_seq')) {
            Schema::table('customer_returns', function (Blueprint $table) {
                $table->unique(['organization_id', 'return_seq'], 'uq_org_customer_return_seq');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_returns') || ! Schema::hasColumn('customer_returns', 'return_seq')) {
            return;
        }

        if ($this->indexExists('customer_returns', 'uq_org_customer_return_seq')) {
            Schema::table('customer_returns', function (Blueprint $table) {
                $table->dropUnique('uq_org_customer_return_seq');
            });
        }

        Schema::table('customer_returns', function (Blueprint $table) {
            $table->dropColumn('return_seq');
        });
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
