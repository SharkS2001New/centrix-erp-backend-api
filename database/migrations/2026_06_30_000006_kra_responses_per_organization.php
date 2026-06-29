<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kra_responses')) {
            return;
        }

        if (! Schema::hasColumn('kra_responses', 'organization_id')) {
            Schema::table('kra_responses', function (Blueprint $table) {
                $table->integer('organization_id')->nullable()->after('sale_id');
            });
        }

        DB::statement(
            'UPDATE kra_responses kr
             INNER JOIN sales s ON s.id = kr.sale_id
             SET kr.organization_id = s.organization_id
             WHERE kr.organization_id IS NULL',
        );

        $fallbackOrgId = DB::table('organizations')->orderBy('id')->value('id');
        if ($fallbackOrgId) {
            DB::table('kra_responses')->whereNull('organization_id')->update([
                'organization_id' => $fallbackOrgId,
            ]);
        }

        DB::statement('ALTER TABLE kra_responses MODIFY organization_id INT NOT NULL');

        if ($this->indexExists('kra_responses', 'invoice_number')) {
            DB::statement('ALTER TABLE kra_responses DROP INDEX invoice_number');
        }

        if (! $this->indexExists('kra_responses', 'uq_org_kra_invoice_number')) {
            Schema::table('kra_responses', function (Blueprint $table) {
                $table->unique(['organization_id', 'invoice_number'], 'uq_org_kra_invoice_number');
                $table->foreign('organization_id')->references('id')->on('organizations');
                $table->index('organization_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('kra_responses') || ! Schema::hasColumn('kra_responses', 'organization_id')) {
            return;
        }

        if ($this->indexExists('kra_responses', 'uq_org_kra_invoice_number')) {
            Schema::table('kra_responses', function (Blueprint $table) {
                $table->dropForeign(['organization_id']);
                $table->dropUnique('uq_org_kra_invoice_number');
            });
        }

        if (! $this->indexExists('kra_responses', 'invoice_number')) {
            Schema::table('kra_responses', function (Blueprint $table) {
                $table->unique('invoice_number');
            });
        }

        Schema::table('kra_responses', function (Blueprint $table) {
            $table->dropColumn('organization_id');
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
