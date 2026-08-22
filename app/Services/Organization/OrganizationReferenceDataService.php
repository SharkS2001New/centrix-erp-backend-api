<?php

namespace App\Services\Organization;

use Illuminate\Support\Facades\DB;

class OrganizationReferenceDataService
{
    /** @return list<array{method_name: string, method_code: string, requires_reference: bool}> */
    public function paymentMethodTemplates(): array
    {
        return [
            ['method_name' => 'Cash', 'method_code' => 'CASH', 'requires_reference' => false],
            ['method_name' => 'M-Pesa', 'method_code' => 'MPESA', 'requires_reference' => false],
            ['method_name' => 'Equity Bank', 'method_code' => 'EQUITY', 'requires_reference' => false],
            ['method_name' => 'KCB', 'method_code' => 'KCB', 'requires_reference' => false],
            ['method_name' => 'Bank Transfer', 'method_code' => 'BANK', 'requires_reference' => false],
            ['method_name' => 'Cheque', 'method_code' => 'CHEQUE', 'requires_reference' => false],
            ['method_name' => 'Card', 'method_code' => 'CARD', 'requires_reference' => false],
            ['method_name' => 'Credit / Invoice', 'method_code' => 'CREDIT', 'requires_reference' => false],
            ['method_name' => 'Voucher', 'method_code' => 'VOUCHER', 'requires_reference' => false],
            ['method_name' => 'Loyalty Points', 'method_code' => 'POINTS', 'requires_reference' => false],
        ];
    }

    /** Seed org-owned payment methods and expense groups for a new tenant. */
    public function seedForOrganization(int $organizationId): void
    {
        if ($organizationId <= 0) {
            return;
        }

        $this->ensurePaymentMethods($organizationId);
        $this->seedExpenseGroups($organizationId);
    }

    /**
     * Insert any missing catalog codes for the org. Never changes is_active on
     * rows that already exist (admins control enable/disable per method).
     */
    public function ensurePaymentMethods(int $organizationId): void
    {
        if ($organizationId <= 0 || ! $this->tableHasOrganizationColumn('payment_methods')) {
            return;
        }

        $existing = DB::table('payment_methods')
            ->where('organization_id', $organizationId)
            ->pluck('method_code')
            ->map(fn ($code) => strtoupper((string) $code))
            ->all();
        $existingSet = array_fill_keys($existing, true);

        foreach ($this->paymentMethodTemplates() as $template) {
            $code = strtoupper($template['method_code']);
            if (isset($existingSet[$code])) {
                continue;
            }
            DB::table('payment_methods')->insert([
                'method_name' => $template['method_name'],
                'method_code' => $code,
                'requires_reference' => $template['requires_reference'],
                'organization_id' => $organizationId,
                'is_active' => true,
            ]);
            $existingSet[$code] = true;
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
