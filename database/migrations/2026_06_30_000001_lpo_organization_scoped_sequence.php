<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lpo_mst')) {
            return;
        }

        if (! Schema::hasColumn('lpo_mst', 'organization_id')) {
            Schema::table('lpo_mst', function (Blueprint $table) {
                $table->integer('organization_id')->nullable()->after('lpo_no');
                $table->unsignedInteger('lpo_seq')->nullable()->after('organization_id');
            });
        }

        DB::statement(
            'UPDATE lpo_mst l
             INNER JOIN suppliers s ON s.id = l.supplier_id
             SET l.organization_id = s.organization_id
             WHERE l.organization_id IS NULL AND s.organization_id IS NOT NULL',
        );

        $fallbackOrgId = DB::table('organizations')->orderBy('id')->value('id');
        if ($fallbackOrgId) {
            DB::table('lpo_mst')->whereNull('organization_id')->update([
                'organization_id' => $fallbackOrgId,
            ]);
        }

        if (Schema::hasColumn('lpo_mst', 'lpo_seq')) {
            $rows = DB::table('lpo_mst')
                ->select('lpo_no', 'organization_id')
                ->whereNull('lpo_seq')
                ->orderBy('organization_id')
                ->orderBy('lpo_no')
                ->get();

            $seqByOrg = [];
            foreach ($rows as $row) {
                $orgId = (int) $row->organization_id;
                $seqByOrg[$orgId] = ($seqByOrg[$orgId] ?? 0) + 1;
                DB::table('lpo_mst')
                    ->where('lpo_no', $row->lpo_no)
                    ->update(['lpo_seq' => $seqByOrg[$orgId]]);
            }
        }

        DB::statement('ALTER TABLE lpo_mst MODIFY organization_id INT NOT NULL');
        DB::statement('ALTER TABLE lpo_mst MODIFY lpo_seq INT UNSIGNED NOT NULL');

        if (! $this->indexExists('lpo_mst', 'uq_org_lpo_seq')) {
            Schema::table('lpo_mst', function (Blueprint $table) {
                $table->unique(['organization_id', 'lpo_seq'], 'uq_org_lpo_seq');
                $table->index('organization_id');
            });
        }

        if (! $this->foreignKeyExists('lpo_mst', 'lpo_mst_organization_id_foreign')) {
            Schema::table('lpo_mst', function (Blueprint $table) {
                $table->foreign('organization_id')->references('id')->on('organizations');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('lpo_mst')) {
            return;
        }

        if ($this->foreignKeyExists('lpo_mst', 'lpo_mst_organization_id_foreign')) {
            Schema::table('lpo_mst', function (Blueprint $table) {
                $table->dropForeign(['organization_id']);
            });
        }

        if ($this->indexExists('lpo_mst', 'uq_org_lpo_seq')) {
            Schema::table('lpo_mst', function (Blueprint $table) {
                $table->dropUnique('uq_org_lpo_seq');
            });
        }

        if (Schema::hasColumn('lpo_mst', 'lpo_seq')) {
            Schema::table('lpo_mst', function (Blueprint $table) {
                $table->dropColumn(['lpo_seq', 'organization_id']);
            });
        }
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

    protected function foreignKeyExists(string $table, string $name): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.table_constraints
             WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = ?',
            [$database, $table, $name, 'FOREIGN KEY'],
        );

        return (int) ($row->c ?? 0) > 0;
    }
};
