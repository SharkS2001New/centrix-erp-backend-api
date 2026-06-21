<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'branch_id')) {
            Schema::table('products', function (Blueprint $table) {
                // Must match branches.id (signed INT) for MySQL 8 FK compatibility.
                $table->integer('branch_id')->nullable()->after('organization_id');
            });
        } else {
            DB::statement('ALTER TABLE products MODIFY branch_id INT NULL');
        }

        Schema::table('products', function (Blueprint $table) {
            if (! $this->branchIdForeignKeyExists()) {
                $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            }
            if (! $this->indexExists('products', 'products_org_branch_idx')) {
                $table->index(['organization_id', 'branch_id'], 'products_org_branch_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'branch_id')) {
                if ($this->branchIdForeignKeyExists()) {
                    $table->dropForeign(['branch_id']);
                }
                if ($this->indexExists('products', 'products_org_branch_idx')) {
                    $table->dropIndex('products_org_branch_idx');
                }
                $table->dropColumn('branch_id');
            }
        });
    }

    protected function branchIdForeignKeyExists(): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return (bool) $connection->selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?
             LIMIT 1',
            [$database, 'products', 'branch_id', 'branches', 'id'],
        );
    }

    protected function indexExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return (bool) $connection->selectOne(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$database, $table, $name],
        );
    }
};
