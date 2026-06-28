<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->scopeCustomerInvoices();
        $this->scopeCustomerReturns();
        $this->scopeCreditNotes();
    }

    public function down(): void
    {
        $this->restoreGlobalUnique('customer_invoices', 'invoice_number', 'uq_org_customer_invoice_number');
        $this->restoreGlobalUnique('customer_returns', 'return_no', 'uq_org_customer_return_no');
        $this->restoreGlobalUnique('credit_notes', 'credit_note_no', 'uq_org_credit_note_no');
    }

    protected function scopeCustomerInvoices(): void
    {
        if (! Schema::hasTable('customer_invoices')) {
            return;
        }

        if ($this->indexExists('customer_invoices', 'invoice_number')) {
            DB::statement('ALTER TABLE customer_invoices DROP INDEX invoice_number');
        }

        if (! $this->indexExists('customer_invoices', 'uq_org_customer_invoice_number')) {
            Schema::table('customer_invoices', function (Blueprint $table) {
                $table->unique(['organization_id', 'invoice_number'], 'uq_org_customer_invoice_number');
            });
        }
    }

    protected function scopeCustomerReturns(): void
    {
        if (! Schema::hasTable('customer_returns')) {
            return;
        }

        if ($this->indexExists('customer_returns', 'return_no')) {
            DB::statement('ALTER TABLE customer_returns DROP INDEX return_no');
        }

        if (! $this->indexExists('customer_returns', 'uq_org_customer_return_no')) {
            Schema::table('customer_returns', function (Blueprint $table) {
                $table->unique(['organization_id', 'return_no'], 'uq_org_customer_return_no');
            });
        }
    }

    protected function scopeCreditNotes(): void
    {
        if (! Schema::hasTable('credit_notes')) {
            return;
        }

        if ($this->indexExists('credit_notes', 'credit_note_no')) {
            DB::statement('ALTER TABLE credit_notes DROP INDEX credit_note_no');
        }

        if (! $this->indexExists('credit_notes', 'uq_org_credit_note_no')) {
            Schema::table('credit_notes', function (Blueprint $table) {
                $table->unique(['organization_id', 'credit_note_no'], 'uq_org_credit_note_no');
            });
        }
    }

    protected function restoreGlobalUnique(string $table, string $column, string $compositeIndex): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if ($this->indexExists($table, $compositeIndex)) {
            Schema::table($table, function (Blueprint $blueprint) use ($compositeIndex) {
                $blueprint->dropUnique($compositeIndex);
            });
        }

        if (! $this->indexExists($table, $column)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->unique($column);
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
