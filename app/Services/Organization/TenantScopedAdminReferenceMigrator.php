<?php

namespace App\Services\Organization;

use Illuminate\Support\Facades\DB;

class TenantScopedAdminReferenceMigrator
{
    public function run(): void
    {
        $this->migratePaymentMethods();
        $this->migrateExpenseGroups();
        $this->migrateAuditLogs();
    }

    protected function migratePaymentMethods(): void
    {
        if (! DB::getSchemaBuilder()->hasColumn('payment_methods', 'organization_id')) {
            return;
        }

        $globalMethods = DB::table('payment_methods')->whereNull('organization_id')->get();
        if ($globalMethods->isEmpty()) {
            return;
        }

        $organizations = DB::table('organizations')->orderBy('id')->pluck('id');

        foreach ($organizations as $organizationId) {
            $orgId = (int) $organizationId;
            $idMap = [];

            foreach ($globalMethods as $method) {
                $existingId = DB::table('payment_methods')
                    ->where('organization_id', $orgId)
                    ->where(function ($query) use ($method) {
                        $query->where('method_code', $method->method_code)
                            ->orWhere('method_name', $method->method_name);
                    })
                    ->value('id');

                if ($existingId) {
                    $idMap[(int) $method->id] = (int) $existingId;
                    continue;
                }

                $payload = (array) $method;
                unset($payload['id']);
                $payload['organization_id'] = $orgId;
                $newId = DB::table('payment_methods')->insertGetId($payload);
                $idMap[(int) $method->id] = (int) $newId;
            }

            $this->rewritePaymentMethodReferences($orgId, $idMap);
        }

        DB::table('payment_methods')->whereNull('organization_id')->delete();
    }

    /** @param  array<int, int>  $idMap */
    protected function rewritePaymentMethodReferences(int $organizationId, array $idMap): void
    {
        if ($idMap === []) {
            return;
        }

        $this->rewritePaymentMethodColumn('sale_payments', 'sales', 'sale_id', $organizationId, $idMap);
        $this->rewritePaymentMethodColumn('customer_invoice_payments', 'customer_invoices', 'customer_invoice_id', $organizationId, $idMap);
        $this->rewriteSupplierPaymentMethods($organizationId, $idMap);
        $this->rewriteExpensePaymentMethods($organizationId, $idMap);
    }

    /**
     * @param  array<int, int>  $idMap
     */
    protected function rewritePaymentMethodColumn(
        string $paymentTable,
        string $parentTable,
        string $parentKey,
        int $organizationId,
        array $idMap,
        ?string $orgColumn = null,
    ): void {
        if (! DB::getSchemaBuilder()->hasTable($paymentTable)) {
            return;
        }

        $orgColumn ??= 'parent.organization_id';

        foreach ($idMap as $oldId => $newId) {
            if ($oldId === $newId) {
                continue;
            }

            DB::statement(
                "UPDATE {$paymentTable} p
                 INNER JOIN {$parentTable} parent ON parent.id = p.{$parentKey}
                 SET p.payment_method_id = ?
                 WHERE p.payment_method_id = ? AND {$orgColumn} = ?",
                [$newId, $oldId, $organizationId],
            );
        }
    }

    /** @param  array<int, int>  $idMap */
    protected function rewriteSupplierPaymentMethods(int $organizationId, array $idMap): void
    {
        if (! DB::getSchemaBuilder()->hasTable('supplier_payments')) {
            return;
        }

        foreach ($idMap as $oldId => $newId) {
            if ($oldId === $newId) {
                continue;
            }

            DB::statement(
                'UPDATE supplier_payments
                 SET payment_method_id = ?
                 WHERE payment_method_id = ? AND organization_id = ?',
                [$newId, $oldId, $organizationId],
            );
        }
    }

    /** @param  array<int, int>  $idMap */
    protected function rewriteExpensePaymentMethods(int $organizationId, array $idMap): void
    {
        if (! DB::getSchemaBuilder()->hasTable('expenses')) {
            return;
        }

        foreach ($idMap as $oldId => $newId) {
            if ($oldId === $newId) {
                continue;
            }

            DB::statement(
                'UPDATE expenses e
                 INNER JOIN branches b ON b.id = e.branch_id
                 SET e.payment_method_id = ?
                 WHERE e.payment_method_id = ? AND b.organization_id = ?',
                [$newId, $oldId, $organizationId],
            );
        }
    }

    protected function migrateExpenseGroups(): void
    {
        if (! DB::getSchemaBuilder()->hasColumn('expense_groups', 'organization_id')) {
            return;
        }

        $globalGroups = DB::table('expense_groups')->whereNull('organization_id')->get();
        if ($globalGroups->isEmpty()) {
            return;
        }

        $organizations = DB::table('organizations')->orderBy('id')->pluck('id');

        foreach ($organizations as $organizationId) {
            $orgId = (int) $organizationId;
            $idMap = [];

            foreach ($globalGroups as $group) {
                $existingId = DB::table('expense_groups')
                    ->where('organization_id', $orgId)
                    ->where('group_name', $group->group_name)
                    ->value('id');

                if ($existingId) {
                    $idMap[(int) $group->id] = (int) $existingId;
                    continue;
                }

                $payload = (array) $group;
                unset($payload['id']);
                $payload['organization_id'] = $orgId;
                $newId = DB::table('expense_groups')->insertGetId($payload);
                $idMap[(int) $group->id] = (int) $newId;
            }

            foreach ($idMap as $oldId => $newId) {
                if ($oldId === $newId) {
                    continue;
                }

                DB::statement(
                    'UPDATE expenses e
                     INNER JOIN branches b ON b.id = e.branch_id
                     SET e.expense_group_id = ?
                     WHERE e.expense_group_id = ? AND b.organization_id = ?',
                    [$newId, $oldId, $orgId],
                );
            }
        }

        DB::table('expense_groups')->whereNull('organization_id')->delete();
    }

    protected function migrateAuditLogs(): void
    {
        if (! DB::getSchemaBuilder()->hasColumn('audit_logs', 'organization_id')) {
            return;
        }

        DB::statement(
            'UPDATE audit_logs al
             INNER JOIN users u ON u.id = al.user_id
             SET al.organization_id = u.organization_id
             WHERE al.organization_id IS NULL AND u.organization_id IS NOT NULL',
        );

        $fallbackOrgId = DB::table('organizations')->orderBy('id')->value('id');
        if ($fallbackOrgId) {
            DB::table('audit_logs')->whereNull('organization_id')->update([
                'organization_id' => $fallbackOrgId,
            ]);
        }
    }
}
