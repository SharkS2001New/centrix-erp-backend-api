<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<array{table: string, columns: list<string>}> */
    protected array $compositeCustomerForeignKeys = [
        ['table' => 'sales', 'columns' => ['organization_id', 'customer_num']],
        ['table' => 'customer_invoices', 'columns' => ['organization_id', 'customer_num']],
        ['table' => 'customer_invoice_payments', 'columns' => ['organization_id', 'customer_num']],
        ['table' => 'loyalty_cards', 'columns' => ['organization_id', 'customer_num']],
        ['table' => 'customer_returns', 'columns' => ['organization_id', 'customer_num']],
        ['table' => 'credit_notes', 'columns' => ['organization_id', 'customer_num']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        if (! Schema::hasColumn('customers', 'id')) {
            $this->dropForeignKeysReferencing('customers', 'customer_num');

            DB::statement(
                'ALTER TABLE customers
                 DROP PRIMARY KEY,
                 ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST',
            );

            if (! $this->indexExists('customers', 'uq_org_customer_num')) {
                Schema::table('customers', function (Blueprint $table) {
                    $table->unique(['organization_id', 'customer_num'], 'uq_org_customer_num');
                });
            }
        }

        $this->replaceSingleColumnCustomerForeignKeys();
        $this->replaceCustomerBalanceTrigger();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $this->dropCompositeCustomerForeignKeys();
        DB::statement('DROP TRIGGER IF EXISTS trg_update_ar_on_payment');

        if (Schema::hasColumn('customers', 'id')) {
            if ($this->indexExists('customers', 'uq_org_customer_num')) {
                Schema::table('customers', function (Blueprint $table) {
                    $table->dropUnique('uq_org_customer_num');
                });
            }

            DB::statement('ALTER TABLE customers DROP PRIMARY KEY, DROP COLUMN id, ADD PRIMARY KEY (customer_num)');
        }

        $this->restoreLegacyCustomerForeignKeys();
        $this->restoreLegacyCustomerBalanceTrigger();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function replaceSingleColumnCustomerForeignKeys(): void
    {
        foreach ($this->compositeCustomerForeignKeys as $spec) {
            if (! Schema::hasTable($spec['table'])) {
                continue;
            }

            $this->dropForeignKeysReferencingTableColumn($spec['table'], 'customer_num');

            $name = $spec['table'].'_organization_customer_foreign';
            if ($this->foreignKeyExists($spec['table'], $name)) {
                continue;
            }

            Schema::table($spec['table'], function (Blueprint $table) use ($spec, $name) {
                $table->foreign($spec['columns'], $name)
                    ->references(['organization_id', 'customer_num'])
                    ->on('customers');
            });
        }
    }

    protected function dropForeignKeysReferencingTableColumn(string $table, string $column): void
    {
        $database = Schema::getConnection()->getDatabaseName();
        $foreignKeys = DB::select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database, $table, $column],
        );

        foreach ($foreignKeys as $foreignKey) {
            DB::statement(
                'ALTER TABLE `'.$table.'` DROP FOREIGN KEY `'.$foreignKey->CONSTRAINT_NAME.'`',
            );
        }
    }

    protected function dropCompositeCustomerForeignKeys(): void
    {
        foreach ($this->compositeCustomerForeignKeys as $spec) {
            if (! Schema::hasTable($spec['table'])) {
                continue;
            }

            $name = $spec['table'].'_organization_customer_foreign';
            if (! $this->foreignKeyExists($spec['table'], $name)) {
                continue;
            }

            Schema::table($spec['table'], function (Blueprint $table) use ($name) {
                $table->dropForeign($name);
            });
        }
    }

    protected function restoreLegacyCustomerForeignKeys(): void
    {
        foreach ($this->compositeCustomerForeignKeys as $spec) {
            if (! Schema::hasTable($spec['table']) || ! Schema::hasColumn($spec['table'], 'customer_num')) {
                continue;
            }

            $legacyName = $spec['table'].'_customer_num_foreign';
            if ($this->foreignKeyExists($spec['table'], $legacyName)) {
                continue;
            }

            Schema::table($spec['table'], function (Blueprint $table) use ($legacyName) {
                $table->foreign('customer_num', $legacyName)->references('customer_num')->on('customers');
            });
        }
    }

    protected function replaceCustomerBalanceTrigger(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_update_ar_on_payment');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_update_ar_on_payment
AFTER INSERT ON customer_invoice_payments
FOR EACH ROW
BEGIN
    DECLARE new_paid DECIMAL(10,2);
    DECLARE inv_total DECIMAL(10,2);

    SELECT (amount_paid + NEW.amount_paid), invoice_total
    INTO new_paid, inv_total
    FROM customer_invoices WHERE id = NEW.customer_invoice_id;

    UPDATE customer_invoices
    SET amount_paid    = new_paid,
        payment_status = CASE
            WHEN new_paid >= inv_total THEN 2
            WHEN new_paid > 0         THEN 1
            ELSE 0
        END,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = NEW.customer_invoice_id;

    UPDATE customers
    SET current_balance = (
        SELECT COALESCE(SUM(balance_due), 0)
        FROM customer_invoices
        WHERE organization_id = NEW.organization_id
          AND customer_num = NEW.customer_num
          AND payment_status IN (0,1)
          AND deleted_at IS NULL
    )
    WHERE organization_id = NEW.organization_id
      AND customer_num = NEW.customer_num;
END
SQL);
    }

    protected function restoreLegacyCustomerBalanceTrigger(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_update_ar_on_payment');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_update_ar_on_payment
AFTER INSERT ON customer_invoice_payments
FOR EACH ROW
BEGIN
    DECLARE new_paid DECIMAL(10,2);
    DECLARE inv_total DECIMAL(10,2);

    SELECT (amount_paid + NEW.amount_paid), invoice_total
    INTO new_paid, inv_total
    FROM customer_invoices WHERE id = NEW.customer_invoice_id;

    UPDATE customer_invoices
    SET amount_paid    = new_paid,
        payment_status = CASE
            WHEN new_paid >= inv_total THEN 2
            WHEN new_paid > 0         THEN 1
            ELSE 0
        END,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = NEW.customer_invoice_id;

    UPDATE customers
    SET current_balance = (
        SELECT COALESCE(SUM(balance_due), 0)
        FROM customer_invoices
        WHERE customer_num = NEW.customer_num
          AND payment_status IN (0,1)
          AND deleted_at IS NULL
    )
    WHERE customer_num = NEW.customer_num;
END
SQL);
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
