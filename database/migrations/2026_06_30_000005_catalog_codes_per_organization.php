<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->scopeProducts();
        $this->scopeSuppliers();
        $this->scopeRoutes();
    }

    public function down(): void
    {
        // Restoring global uniqueness requires manual rollback on production clones.
    }

    protected function scopeProducts(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $this->dropForeignKeysReferencing('products', 'product_code');

        if ($this->indexExists('products', 'product_code')) {
            DB::statement('ALTER TABLE products DROP INDEX product_code');
        }
        if ($this->indexExists('products', 'uq_product_code')) {
            DB::statement('ALTER TABLE products DROP INDEX uq_product_code');
        }

        if (! $this->indexExists('products', 'uq_org_product_code')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unique(['organization_id', 'product_code'], 'uq_org_product_code');
            });
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function scopeSuppliers(): void
    {
        if (! Schema::hasTable('suppliers')) {
            return;
        }

        if ($this->indexExists('suppliers', 'supplier_code')) {
            DB::statement('ALTER TABLE suppliers DROP INDEX supplier_code');
        }

        if (! $this->indexExists('suppliers', 'uq_org_supplier_code')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->unique(['organization_id', 'supplier_code'], 'uq_org_supplier_code');
            });
        }
    }

    protected function scopeRoutes(): void
    {
        if (! Schema::hasTable('routes')) {
            return;
        }

        if (! Schema::hasColumn('routes', 'organization_id')) {
            Schema::table('routes', function (Blueprint $table) {
                $table->integer('organization_id')->nullable()->after('id');
            });
        }

        DB::statement(
            'UPDATE routes r
             INNER JOIN (
                 SELECT route_id, MIN(organization_id) AS organization_id
                 FROM customers
                 WHERE route_id IS NOT NULL
                 GROUP BY route_id
             ) c ON c.route_id = r.id
             SET r.organization_id = c.organization_id
             WHERE r.organization_id IS NULL',
        );

        $fallbackOrgId = DB::table('organizations')->orderBy('id')->value('id');
        if ($fallbackOrgId) {
            DB::table('routes')->whereNull('organization_id')->update([
                'organization_id' => $fallbackOrgId,
            ]);
        }

        DB::statement('ALTER TABLE routes MODIFY organization_id INT NOT NULL');

        if ($this->indexExists('routes', 'route_name')) {
            DB::statement('ALTER TABLE routes DROP INDEX route_name');
        }

        if (! $this->indexExists('routes', 'uq_org_route_name')) {
            Schema::table('routes', function (Blueprint $table) {
                $table->unique(['organization_id', 'route_name'], 'uq_org_route_name');
                $table->foreign('organization_id')->references('id')->on('organizations');
            });
        }
    }

    protected function dropForeignKeysReferencing(string $referencedTable, string $referencedColumn): void
    {
        $database = Schema::getConnection()->getDatabaseName();
        $foreignKeys = DB::select(
            'SELECT TABLE_NAME, CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND REFERENCED_TABLE_NAME = ?
               AND REFERENCED_COLUMN_NAME = ?',
            [$database, $referencedTable, $referencedColumn],
        );

        foreach ($foreignKeys as $foreignKey) {
            DB::statement(
                'ALTER TABLE `'.$foreignKey->TABLE_NAME.'` DROP FOREIGN KEY `'.$foreignKey->CONSTRAINT_NAME.'`',
            );
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
