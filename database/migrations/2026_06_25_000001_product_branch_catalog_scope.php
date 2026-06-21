<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
                $table->unsignedInteger('branch_id')->nullable()->after('organization_id');
            });
        } else {
            DB::statement('ALTER TABLE products MODIFY branch_id INT UNSIGNED NULL');
        }

        Schema::table('products', function (Blueprint $table) {
            if (! $this->foreignKeyExists('products', 'products_branch_id_foreign')) {
                $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            }
            if (! $this->indexExists('products', 'products_org_branch_idx')) {
                $table->index(['organization_id', 'branch_id'], 'products_org_branch_idx');
            }
        });
    }
            if (! $this->foreignKeyExists('products', 'products_branch_id_foreign')) {
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
                if ($this->foreignKeyExists('products', 'products_branch_id_foreign')) {
                    $table->dropForeign(['branch_id']);
                }
                if ($this->indexExists('products', 'products_org_branch_idx')) {
                    $table->dropIndex('products_org_branch_idx');
                }
                $table->dropColumn('branch_id');
            }
        });
    }

    protected function foreignKeyExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return (bool) $connection->selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$database, $table, $name, 'FOREIGN KEY'],
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
