<?php

namespace App\Services\Organization;

use Illuminate\Support\Facades\DB;

class OrganizationReferenceDataService
{
    /** Seed org-owned payment methods and expense groups for a new tenant. */
    public function seedForOrganization(int $organizationId): void
    {
        if ($organizationId <= 0) {
            return;
        }

        $this->seedPaymentMethods($organizationId);
        $this->seedExpenseGroups($organizationId);
    }

    protected function seedPaymentMethods(int $organizationId): void
    {
        if (! $this->tableHasOrganizationColumn('payment_methods')) {
            return;
        }

        if (DB::table('payment_methods')->where('organization_id', $organizationId)->exists()) {
            return;
        }

        $templates = [
            ['method_name' => 'Cash', 'method_code' => 'CASH', 'requires_reference' => false],
            ['method_name' => 'M-Pesa', 'method_code' => 'MPESA', 'requires_reference' => true],
            ['method_name' => 'Bank Transfer', 'method_code' => 'BANK', 'requires_reference' => true],
            ['method_name' => 'Cheque', 'method_code' => 'CHEQUE', 'requires_reference' => true],
            ['method_name' => 'Card', 'method_code' => 'CARD', 'requires_reference' => true],
            ['method_name' => 'Voucher', 'method_code' => 'VOUCHER', 'requires_reference' => false],
            ['method_name' => 'Loyalty Points', 'method_code' => 'POINTS', 'requires_reference' => false],
        ];

        foreach ($templates as $template) {
            DB::table('payment_methods')->insert([
                ...$template,
                'organization_id' => $organizationId,
                'is_active' => true,
            ]);
        }
    }

    protected function seedExpenseGroups(int $organizationId): void
    {
        if (! $this->tableHasOrganizationColumn('expense_groups')) {
            return;
        }

        if (DB::table('expense_groups')->where('organization_id', $organizationId)->exists()) {
            return;
        }

        foreach (['Fuel', 'Utilities', 'Rent', 'Salaries', 'Office Supplies', 'Maintenance', 'Other'] as $groupName) {
            DB::table('expense_groups')->insert([
                'group_name' => $groupName,
                'organization_id' => $organizationId,
            ]);
        }
    }

    protected function tableHasOrganizationColumn(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table)
            && DB::getSchemaBuilder()->hasColumn($table, 'organization_id');
    }
}
