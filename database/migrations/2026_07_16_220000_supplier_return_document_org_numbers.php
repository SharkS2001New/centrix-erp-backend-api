<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier return documents were labeled from the global PK (SR-12).
 * Add org-scoped document_seq / document_no like customer returns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_return_documents')) {
            return;
        }

        if (! Schema::hasColumn('supplier_return_documents', 'document_seq')) {
            Schema::table('supplier_return_documents', function (Blueprint $table) {
                $table->unsignedInteger('document_seq')->nullable()->after('organization_id');
                $table->string('document_no', 20)->nullable()->after('document_seq');
            });
        }

        $rows = DB::table('supplier_return_documents')
            ->whereNull('document_seq')
            ->orderBy('organization_id')
            ->orderBy('id')
            ->get(['id', 'organization_id']);

        $seqByOrg = [];
        foreach ($rows as $row) {
            $orgId = (int) $row->organization_id;
            $seqByOrg[$orgId] = ($seqByOrg[$orgId] ?? 0) + 1;
            $seq = $seqByOrg[$orgId];
            DB::table('supplier_return_documents')
                ->where('id', $row->id)
                ->update([
                    'document_seq' => $seq,
                    'document_no' => 'SR-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                ]);
        }

        if (Schema::hasColumn('supplier_return_documents', 'document_seq')) {
            DB::statement('ALTER TABLE supplier_return_documents MODIFY document_seq INT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE supplier_return_documents MODIFY document_no VARCHAR(20) NOT NULL');
        }

        if (! $this->indexExists('supplier_return_documents', 'uq_org_supplier_return_document_seq')) {
            Schema::table('supplier_return_documents', function (Blueprint $table) {
                $table->unique(['organization_id', 'document_seq'], 'uq_org_supplier_return_document_seq');
                $table->unique(['organization_id', 'document_no'], 'uq_org_supplier_return_document_no');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('supplier_return_documents')) {
            return;
        }

        if ($this->indexExists('supplier_return_documents', 'uq_org_supplier_return_document_seq')) {
            Schema::table('supplier_return_documents', function (Blueprint $table) {
                $table->dropUnique('uq_org_supplier_return_document_seq');
            });
        }
        if ($this->indexExists('supplier_return_documents', 'uq_org_supplier_return_document_no')) {
            Schema::table('supplier_return_documents', function (Blueprint $table) {
                $table->dropUnique('uq_org_supplier_return_document_no');
            });
        }

        if (Schema::hasColumn('supplier_return_documents', 'document_seq')) {
            Schema::table('supplier_return_documents', function (Blueprint $table) {
                $table->dropColumn(['document_seq', 'document_no']);
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
};
