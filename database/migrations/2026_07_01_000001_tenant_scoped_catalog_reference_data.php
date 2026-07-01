<?php

use App\Services\Catalog\TenantScopedCatalogReferenceMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    protected array $tables = ['vats', 'uoms', 'categories', 'sub_categories'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'organization_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $after = $table === 'sub_categories' ? 'category_id' : 'id';
                $blueprint->integer('organization_id')->nullable()->after($after);
            });
        }

        app(TenantScopedCatalogReferenceMigrator::class)->run();
        app(TenantScopedCatalogReferenceMigrator::class)->finalizeOrganizationIds($this->tables);

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'organization_id')) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} MODIFY organization_id INT NOT NULL");
        }

        if (Schema::hasTable('vats') && $this->indexExists('vats', 'vats_vat_code_unique')) {
            Schema::table('vats', function (Blueprint $table) {
                $table->dropUnique('vats_vat_code_unique');
            });
        }

        if (Schema::hasTable('vats') && $this->indexExists('vats', 'vat_code')) {
            DB::statement('ALTER TABLE vats DROP INDEX vat_code');
        }

        if (Schema::hasTable('vats') && ! $this->indexExists('vats', 'uq_org_vat_code')) {
            Schema::table('vats', function (Blueprint $table) {
                $table->unique(['organization_id', 'vat_code'], 'uq_org_vat_code');
                $table->foreign('organization_id')->references('id')->on('organizations');
                $table->index('organization_id');
            });
        }

        foreach (['uoms', 'categories', 'sub_categories'] as $table) {
            if (! Schema::hasTable($table) || $this->indexExists($table, "idx_{$table}_organization_id")) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->foreign('organization_id')->references('id')->on('organizations');
                $blueprint->index('organization_id', "idx_{$table}_organization_id");
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vats') && $this->indexExists('vats', 'uq_org_vat_code')) {
            Schema::table('vats', function (Blueprint $table) {
                $table->dropForeign(['organization_id']);
                $table->dropUnique('uq_org_vat_code');
                $table->dropIndex(['organization_id']);
            });

            Schema::table('vats', function (Blueprint $table) {
                $table->unique('vat_code');
            });
        }

        foreach (['sub_categories', 'categories', 'uoms'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'organization_id')) {
                continue;
            }

            if ($this->indexExists($table, "idx_{$table}_organization_id")) {
                Schema::table($table, function (Blueprint $blueprint) use ($table) {
                    $blueprint->dropForeign(['organization_id']);
                    $blueprint->dropIndex("idx_{$table}_organization_id");
                });
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('organization_id');
            });
        }

        if (Schema::hasTable('vats') && Schema::hasColumn('vats', 'organization_id')) {
            Schema::table('vats', function (Blueprint $table) {
                $table->dropColumn('organization_id');
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
