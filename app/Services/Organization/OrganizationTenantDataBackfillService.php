<?php

namespace App\Services\Organization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill and reconcile organization_id on tenant-owned rows.
 * Anchors data to branches.organization_id so multi-tenant reports stay isolated.
 */
class OrganizationTenantDataBackfillService
{
    /** @return array<string, int> */
    public function run(?int $onlyOrganizationId = null): array
    {
        $stats = [];

        if (Schema::hasTable('customer_invoices') && Schema::hasColumn('customer_invoices', 'organization_id')) {
            $stats['customer_invoices_from_sales'] = $this->backfillCustomerInvoicesFromSales($onlyOrganizationId);
            $stats['customer_invoices_from_branches'] = $this->backfillFromBranches('customer_invoices', $onlyOrganizationId);
        }

        if (Schema::hasTable('customer_invoice_payments') && Schema::hasColumn('customer_invoice_payments', 'organization_id')) {
            $stats['customer_invoice_payments_from_invoices'] = $this->backfillCustomerInvoicePaymentsFromInvoices($onlyOrganizationId);
            $stats['customer_invoice_payments_from_branches'] = $this->backfillPaymentsViaInvoiceBranches($onlyOrganizationId);
        }

        foreach (['sales', 'customers', 'stock_receipts', 'products', 'vouchers'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'organization_id') && Schema::hasColumn($table, 'branch_id')) {
                $stats["{$table}_from_branches"] = $this->backfillFromBranches($table, $onlyOrganizationId);
            }
        }

        if (Schema::hasTable('suppliers') && Schema::hasColumn('suppliers', 'organization_id')) {
            $stats['suppliers_from_lpo'] = $this->backfillSuppliersFromLpo($onlyOrganizationId);
            $stats['suppliers_from_products'] = $this->backfillSuppliersFromProducts($onlyOrganizationId);
        }

        if (Schema::hasTable('lpo_mst') && Schema::hasColumn('lpo_mst', 'organization_id')) {
            $stats['lpo_mst_from_suppliers'] = $this->backfillLpoFromSuppliers($onlyOrganizationId);
        }

        if (Schema::hasTable('routes') && Schema::hasColumn('routes', 'organization_id')) {
            $stats['routes_from_customers'] = $this->backfillRoutesFromCustomers($onlyOrganizationId);
        }

        if (Schema::hasTable('kra_responses') && Schema::hasColumn('kra_responses', 'organization_id')) {
            $stats['kra_responses_from_sales'] = $this->backfillKraResponsesFromSales($onlyOrganizationId);
        }

        if (Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'organization_id')) {
            $stats['audit_logs_from_users'] = $this->backfillAuditLogsFromUsers($onlyOrganizationId);
        }

        foreach (['loyalty_cards', 'credit_notes', 'customer_returns'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'organization_id')) {
                $stats["{$table}_from_customers"] = $this->backfillFromCustomers($table, $onlyOrganizationId);
            }
        }

        return $stats;
    }

    /** @return array<string, int> */
    public function audit(?int $onlyOrganizationId = null): array
    {
        $issues = [];

        foreach ($this->tablesToAudit() as $spec) {
            $count = $this->countMismatchedRows($spec['table'], $spec['branch_column'] ?? 'branch_id', $onlyOrganizationId);
            if ($count > 0) {
                $issues[$spec['table']] = $count;
            }
        }

        if (Schema::hasTable('customer_invoices') && Schema::hasColumn('customer_invoices', 'organization_id')) {
            $issues['customer_invoices_null_org'] = $this->countNullOrganization('customer_invoices', $onlyOrganizationId);
        }

        if (Schema::hasTable('customer_invoice_payments') && Schema::hasColumn('customer_invoice_payments', 'organization_id')) {
            $issues['customer_invoice_payments_null_org'] = $this->countNullOrganization('customer_invoice_payments', $onlyOrganizationId);
        }

        return $issues;
    }

    protected function backfillCustomerInvoicesFromSales(?int $onlyOrganizationId): int
    {
        if (! Schema::hasTable('sales') || ! Schema::hasColumn('sales', 'organization_id')) {
            return 0;
        }

        $sql = '
            UPDATE customer_invoices ci
            INNER JOIN sales s ON s.id = ci.sale_id
            SET ci.organization_id = s.organization_id
            WHERE (ci.organization_id IS NULL OR ci.organization_id <> s.organization_id)
        ';
        $bindings = [];

        if ($onlyOrganizationId !== null) {
            $sql .= ' AND s.organization_id = ?';
            $bindings[] = $onlyOrganizationId;
        }

        return DB::affectingStatement($sql, $bindings);
    }

    protected function backfillCustomerInvoicePaymentsFromInvoices(?int $onlyOrganizationId): int
    {
        if (! Schema::hasColumn('customer_invoices', 'organization_id')) {
            return 0;
        }

        $sql = '
            UPDATE customer_invoice_payments cip
            INNER JOIN customer_invoices ci ON ci.id = cip.customer_invoice_id
            SET cip.organization_id = ci.organization_id
            WHERE (cip.organization_id IS NULL OR cip.organization_id <> ci.organization_id)
        ';
        $bindings = [];

        if ($onlyOrganizationId !== null) {
            $sql .= ' AND ci.organization_id = ?';
            $bindings[] = $onlyOrganizationId;
        }

        return DB::affectingStatement($sql, $bindings);
    }

    protected function backfillPaymentsViaInvoiceBranches(?int $onlyOrganizationId): int
    {
        if (! Schema::hasColumn('customer_invoices', 'branch_id')) {
            return 0;
        }

        $sql = '
            UPDATE customer_invoice_payments cip
            INNER JOIN customer_invoices ci ON ci.id = cip.customer_invoice_id
            INNER JOIN branches b ON b.id = ci.branch_id
            SET cip.organization_id = b.organization_id
            WHERE (cip.organization_id IS NULL OR cip.organization_id <> b.organization_id)
        ';
        $bindings = [];

        if ($onlyOrganizationId !== null) {
            $sql .= ' AND b.organization_id = ?';
            $bindings[] = $onlyOrganizationId;
        }

        return DB::affectingStatement($sql, $bindings);
    }

    protected function backfillFromBranches(string $table, ?int $onlyOrganizationId): int
    {
        if (! Schema::hasColumn($table, 'branch_id') || ! Schema::hasColumn($table, 'organization_id')) {
            return 0;
        }

        $sql = "
            UPDATE {$table} t
            INNER JOIN branches b ON b.id = t.branch_id
            SET t.organization_id = b.organization_id
            WHERE (t.organization_id IS NULL OR t.organization_id <> b.organization_id)
        ";
        $bindings = [];

        if ($onlyOrganizationId !== null) {
            $sql .= ' AND b.organization_id = ?';
            $bindings[] = $onlyOrganizationId;
        }

        return DB::affectingStatement($sql, $bindings);
    }

    protected function backfillFromCustomers(string $table, ?int $onlyOrganizationId): int
    {
        $sql = "
            UPDATE {$table} t
            INNER JOIN customers c ON c.customer_num = t.customer_num
            SET t.organization_id = c.organization_id
            WHERE (t.organization_id IS NULL OR t.organization_id <> c.organization_id)
        ";
        $bindings = [];

        if ($onlyOrganizationId !== null) {
            $sql .= ' AND c.organization_id = ?';
            $bindings[] = $onlyOrganizationId;
        }

        return DB::affectingStatement($sql, $bindings);
    }

    protected function backfillSuppliersFromLpo(?int $onlyOrganizationId): int
    {
        if (! Schema::hasTable('lpo_mst') || ! Schema::hasColumn('lpo_mst', 'organization_id')) {
            return 0;
        }

        $sql = '
            UPDATE suppliers s
            INNER JOIN lpo_mst l ON l.supplier_id = s.id
            SET s.organization_id = l.organization_id
            WHERE (s.organization_id IS NULL OR s.organization_id <> l.organization_id)
        ';
        $bindings = [];

        if ($onlyOrganizationId !== null) {
            $sql .= ' AND l.organization_id = ?';
            $bindings[] = $onlyOrganizationId;
        }

        return DB::affectingStatement($sql, $bindings);
    }

    protected function backfillSuppliersFromProducts(?int $onlyOrganizationId): int
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'supplier_id')) {
            return 0;
        }

        $sql = '
            UPDATE suppliers s
            INNER JOIN (
                SELECT supplier_id, MIN(organization_id) AS organization_id
                FROM products
                WHERE supplier_id IS NOT NULL
                  AND deleted_at IS NULL
                GROUP BY supplier_id
            ) p ON p.supplier_id = s.id
            SET s.organization_id = p.organization_id
            WHERE s.organization_id IS NULL
        ';
        $bindings = [];

        if ($onlyOrganizationId !== null) {
            $sql .= ' AND p.organization_id = ?';
            $bindings[] = $onlyOrganizationId;
        }

        return DB::affectingStatement($sql, $bindings);
    }

    protected function backfillLpoFromSuppliers(?int $onlyOrganizationId): int
    {
        if (! Schema::hasColumn('suppliers', 'organization_id')) {
            return 0;
        }

        $sql = '
            UPDATE lpo_mst l
            INNER JOIN suppliers s ON s.id = l.supplier_id
            SET l.organization_id = s.organization_id
            WHERE (l.organization_id IS NULL OR l.organization_id <> s.organization_id)
              AND s.organization_id IS NOT NULL
        ';
        $bindings = [];

        if ($onlyOrganizationId !== null) {
            $sql .= ' AND s.organization_id = ?';
            $bindings[] = $onlyOrganizationId;
        }

        return DB::affectingStatement($sql, $bindings);
    }

    protected function backfillRoutesFromCustomers(?int $onlyOrganizationId): int
    {
        $sql = '
            UPDATE routes r
            INNER JOIN customers c ON c.route_id = r.id
            SET r.organization_id = c.organization_id
            WHERE (r.organization_id IS NULL OR r.organization_id <> c.organization_id)
        ';
        $bindings = [];

        if ($onlyOrganizationId !== null) {
            $sql .= ' AND c.organization_id = ?';
            $bindings[] = $onlyOrganizationId;
        }

        return DB::affectingStatement($sql, $bindings);
    }

    protected function backfillKraResponsesFromSales(?int $onlyOrganizationId): int
    {
        if (! Schema::hasTable('sales') || ! Schema::hasColumn('sales', 'organization_id')) {
            return 0;
        }

        $sql = '
            UPDATE kra_responses kr
            INNER JOIN sales s ON s.id = kr.sale_id
            SET kr.organization_id = s.organization_id
            WHERE (kr.organization_id IS NULL OR kr.organization_id <> s.organization_id)
        ';
        $bindings = [];

        if ($onlyOrganizationId !== null) {
            $sql .= ' AND s.organization_id = ?';
            $bindings[] = $onlyOrganizationId;
        }

        return DB::affectingStatement($sql, $bindings);
    }

    protected function backfillAuditLogsFromUsers(?int $onlyOrganizationId): int
    {
        if (! Schema::hasColumn('users', 'organization_id')) {
            return 0;
        }

        $sql = '
            UPDATE audit_logs al
            INNER JOIN users u ON u.id = al.user_id
            SET al.organization_id = u.organization_id
            WHERE (al.organization_id IS NULL OR al.organization_id <> u.organization_id)
              AND u.organization_id IS NOT NULL
        ';
        $bindings = [];

        if ($onlyOrganizationId !== null) {
            $sql .= ' AND u.organization_id = ?';
            $bindings[] = $onlyOrganizationId;
        }

        return DB::affectingStatement($sql, $bindings);
    }

    protected function countNullOrganization(string $table, ?int $onlyOrganizationId): int
    {
        $query = DB::table($table)->whereNull('organization_id');

        if ($onlyOrganizationId !== null && Schema::hasColumn($table, 'branch_id')) {
            $query->whereIn('branch_id', function ($sub) use ($onlyOrganizationId) {
                $sub->select('id')
                    ->from('branches')
                    ->where('organization_id', $onlyOrganizationId);
            });
        }

        return (int) $query->count();
    }

    protected function countMismatchedRows(string $table, string $branchColumn, ?int $onlyOrganizationId): int
    {
        if (! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'organization_id')
            || ! Schema::hasColumn($table, $branchColumn)) {
            return 0;
        }

        $query = DB::table("{$table} as t")
            ->join('branches as b', 'b.id', '=', "t.{$branchColumn}")
            ->where(function ($inner) {
                $inner->whereNull('t.organization_id')
                    ->orWhereColumn('t.organization_id', '<>', 'b.organization_id');
            });

        if ($onlyOrganizationId !== null) {
            $query->where('b.organization_id', $onlyOrganizationId);
        }

        return (int) $query->count();
    }

    /** @return list<array{table: string, branch_column?: string}> */
    protected function tablesToAudit(): array
    {
        return [
            ['table' => 'sales'],
            ['table' => 'customers'],
            ['table' => 'products'],
            ['table' => 'stock_receipts'],
            ['table' => 'customer_invoices'],
            ['table' => 'vouchers'],
        ];
    }
}
