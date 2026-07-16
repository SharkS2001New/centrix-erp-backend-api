<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalize organization_id onto remaining branch-only operational tables,
 * and add branch_id to org-only purchasing/payment documents.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const BRANCH_SCOPED_TABLES = [
        'expenses',
        'damages',
        'returns',
        'supplier_returns',
        'inventory_transactions',
        'stock_reservations',
        'stock_movement_history',
        'pod_records',
        'loading_lists',
        'picking_lists',
        'dispatch_trips',
        'temporary_carts',
    ];

    public function up(): void
    {
        foreach (self::BRANCH_SCOPED_TABLES as $table) {
            $this->addOrganizationIdFromBranch($table);
        }

        $this->backfillTemporaryCartsFromUsers();

        foreach (self::BRANCH_SCOPED_TABLES as $table) {
            $this->finalizeOrganizationId($table, allowNull: $table === 'temporary_carts');
        }

        $this->addBranchIdToOrgDocuments('lpo_mst', after: 'organization_id');
        $this->addBranchIdToOrgDocuments('supplier_payments', after: 'organization_id');
        $this->addBranchIdToOrgDocuments('customer_invoice_payments', after: 'organization_id');

        $this->backfillLpoBranchFromCreator();
        $this->backfillSupplierPaymentBranch();
        $this->backfillCustomerInvoicePaymentBranch();
    }

    public function down(): void
    {
        foreach (['lpo_mst', 'supplier_payments', 'customer_invoice_payments'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('branch_id');
                });
            }
        }

        foreach (self::BRANCH_SCOPED_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'organization_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if ($this->foreignKeyExists($table, $table.'_organization_id_foreign')) {
                    $blueprint->dropForeign(['organization_id']);
                }
                $blueprint->dropColumn('organization_id');
            });
        }
    }

    protected function addOrganizationIdFromBranch(string $table): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'organization_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->integer('organization_id')->nullable()->after('id');
            $blueprint->index('organization_id');
        });

        if (Schema::hasTable('branches') && Schema::hasColumn($table, 'branch_id')) {
            DB::statement("
                UPDATE {$table} t
                INNER JOIN branches b ON b.id = t.branch_id
                SET t.organization_id = b.organization_id
                WHERE t.organization_id IS NULL
            ");
        }
    }

    protected function backfillTemporaryCartsFromUsers(): void
    {
        if (! Schema::hasTable('temporary_carts')
            || ! Schema::hasColumn('temporary_carts', 'organization_id')
            || ! Schema::hasTable('users')) {
            return;
        }

        DB::statement('
            UPDATE temporary_carts tc
            INNER JOIN users u ON u.id = tc.user_id
            SET tc.organization_id = u.organization_id
            WHERE tc.organization_id IS NULL
              AND u.organization_id IS NOT NULL
        ');
    }

    protected function finalizeOrganizationId(string $table, bool $allowNull = false): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'organization_id')) {
            return;
        }

        if (! $allowNull) {
            $fallbackOrgId = (int) (DB::table('organizations')->orderBy('id')->value('id') ?? 0);
            if ($fallbackOrgId > 0) {
                DB::table($table)->whereNull('organization_id')->update(['organization_id' => $fallbackOrgId]);
            }
            DB::statement("ALTER TABLE {$table} MODIFY organization_id INT NOT NULL");
        }

        if (! $this->foreignKeyExists($table, $table.'_organization_id_foreign')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('organization_id')->references('id')->on('organizations');
            });
        }
    }

    protected function addBranchIdToOrgDocuments(string $table, string $after): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'branch_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($after) {
            $blueprint->integer('branch_id')->nullable()->after($after);
            $blueprint->index('branch_id');
        });
    }

    protected function backfillLpoBranchFromCreator(): void
    {
        if (! Schema::hasTable('lpo_mst') || ! Schema::hasColumn('lpo_mst', 'branch_id')) {
            return;
        }

        if (Schema::hasTable('users') && Schema::hasColumn('lpo_mst', 'created_by')) {
            DB::statement('
                UPDATE lpo_mst l
                INNER JOIN users u ON u.id = l.created_by
                SET l.branch_id = u.branch_id
                WHERE l.branch_id IS NULL
                  AND u.branch_id IS NOT NULL
            ');
        }
    }

    protected function backfillSupplierPaymentBranch(): void
    {
        if (! Schema::hasTable('supplier_payments') || ! Schema::hasColumn('supplier_payments', 'branch_id')) {
            return;
        }

        if (Schema::hasTable('users') && Schema::hasColumn('supplier_payments', 'paid_by')) {
            DB::statement('
                UPDATE supplier_payments sp
                INNER JOIN users u ON u.id = sp.paid_by
                SET sp.branch_id = u.branch_id
                WHERE sp.branch_id IS NULL
                  AND u.branch_id IS NOT NULL
            ');
        }
    }

    protected function backfillCustomerInvoicePaymentBranch(): void
    {
        if (! Schema::hasTable('customer_invoice_payments')
            || ! Schema::hasColumn('customer_invoice_payments', 'branch_id')) {
            return;
        }

        if (Schema::hasTable('customer_invoices')
            && Schema::hasColumn('customer_invoices', 'branch_id')) {
            DB::statement('
                UPDATE customer_invoice_payments cip
                INNER JOIN customer_invoices ci ON ci.id = cip.customer_invoice_id
                SET cip.branch_id = ci.branch_id
                WHERE cip.branch_id IS NULL
                  AND ci.branch_id IS NOT NULL
            ');
        }
    }

    protected function foreignKeyExists(string $table, string $constraint): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.table_constraints
             WHERE constraint_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = ?',
            [$database, $table, $constraint, 'FOREIGN KEY'],
        );

        return (int) ($row->c ?? 0) > 0;
    }
};
