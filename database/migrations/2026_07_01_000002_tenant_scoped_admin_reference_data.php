<?php

use App\Services\Organization\TenantScopedAdminReferenceMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs') && ! Schema::hasColumn('audit_logs', 'organization_id')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->integer('organization_id')->nullable()->after('user_id');
            });
        }

        if (Schema::hasTable('payment_methods') && ! Schema::hasColumn('payment_methods', 'organization_id')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->integer('organization_id')->nullable()->after('id');
            });
        }

        if (Schema::hasTable('expense_groups') && ! Schema::hasColumn('expense_groups', 'organization_id')) {
            Schema::table('expense_groups', function (Blueprint $table) {
                $table->integer('organization_id')->nullable()->after('id');
            });
        }

        $this->dropLegacyPaymentMethodUniqueIndexes();
        $this->dropLegacyExpenseGroupUniqueIndexes();

        app(TenantScopedAdminReferenceMigrator::class)->run();

        if (Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'organization_id')) {
            DB::statement('ALTER TABLE audit_logs MODIFY organization_id INT NOT NULL');
            if (! $this->indexExists('audit_logs', 'idx_audit_logs_organization_id')) {
                Schema::table('audit_logs', function (Blueprint $table) {
                    $table->foreign('organization_id')->references('id')->on('organizations');
                    $table->index('organization_id', 'idx_audit_logs_organization_id');
                });
            }
        }

        if (Schema::hasTable('payment_methods') && Schema::hasColumn('payment_methods', 'organization_id')) {
            DB::statement('ALTER TABLE payment_methods MODIFY organization_id INT NOT NULL');

            if (! $this->indexExists('payment_methods', 'uq_org_payment_method_code')) {
                Schema::table('payment_methods', function (Blueprint $table) {
                    $table->unique(['organization_id', 'method_code'], 'uq_org_payment_method_code');
                    $table->unique(['organization_id', 'method_name'], 'uq_org_payment_method_name');
                    $table->foreign('organization_id')->references('id')->on('organizations');
                    $table->index('organization_id');
                });
            }
        }

        if (Schema::hasTable('expense_groups') && Schema::hasColumn('expense_groups', 'organization_id')) {
            DB::statement('ALTER TABLE expense_groups MODIFY organization_id INT NOT NULL');
            if (! $this->indexExists('expense_groups', 'idx_expense_groups_organization_id')) {
                Schema::table('expense_groups', function (Blueprint $table) {
                    $table->foreign('organization_id')->references('id')->on('organizations');
                    $table->index('organization_id', 'idx_expense_groups_organization_id');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_methods') && $this->indexExists('payment_methods', 'uq_org_payment_method_code')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->dropForeign(['organization_id']);
                $table->dropUnique('uq_org_payment_method_code');
                $table->dropUnique('uq_org_payment_method_name');
                $table->dropIndex(['organization_id']);
                $table->dropColumn('organization_id');
            });
        }

        if (Schema::hasTable('expense_groups') && Schema::hasColumn('expense_groups', 'organization_id')) {
            Schema::table('expense_groups', function (Blueprint $table) {
                $table->dropForeign(['organization_id']);
                $table->dropIndex('idx_expense_groups_organization_id');
                $table->dropColumn('organization_id');
            });
        }

        if (Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'organization_id')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropForeign(['organization_id']);
                $table->dropIndex('idx_audit_logs_organization_id');
                $table->dropColumn('organization_id');
            });
        }
    }

    protected function dropLegacyPaymentMethodUniqueIndexes(): void
    {
        if (! Schema::hasTable('payment_methods')) {
            return;
        }

        if ($this->indexExists('payment_methods', 'method_code')) {
            DB::statement('ALTER TABLE payment_methods DROP INDEX method_code');
        }
        if ($this->indexExists('payment_methods', 'method_name')) {
            DB::statement('ALTER TABLE payment_methods DROP INDEX method_name');
        }
        if ($this->indexExists('payment_methods', 'payment_methods_method_code_unique')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->dropUnique('payment_methods_method_code_unique');
            });
        }
        if ($this->indexExists('payment_methods', 'payment_methods_method_name_unique')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->dropUnique('payment_methods_method_name_unique');
            });
        }
    }

    protected function dropLegacyExpenseGroupUniqueIndexes(): void
    {
        if (! Schema::hasTable('expense_groups')) {
            return;
        }

        if ($this->indexExists('expense_groups', 'group_name')) {
            DB::statement('ALTER TABLE expense_groups DROP INDEX group_name');
        }
        if ($this->indexExists('expense_groups', 'expense_groups_group_name_unique')) {
            Schema::table('expense_groups', function (Blueprint $table) {
                $table->dropUnique('expense_groups_group_name_unique');
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
