<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\Accounting\StandardChartOfAccounts;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class StandardChartOfAccountsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_provisioned_org_with_accounting_gets_standard_coa(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = \App\Models\User::where('username', 'superadmin')->firstOrFail();
        \Laravel\Sanctum\Sanctum::actingAs($superAdmin);

        $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'ACCTCO',
            'org_name' => 'Accounting Co',
            'org_email' => 'acct@example.com',
            'primary_tel' => '0711000000',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
            'admin_username' => 'acct_admin',
            'admin_email' => 'admin@acctco.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Acct Admin',
        ])->assertCreated();

        $org = Organization::where('company_code', 'ACCTCO')->firstOrFail();
        $seeder = app(StandardChartOfAccounts::class);

        $this->assertTrue($seeder->isSeeded((int) $org->id));
        $this->assertDatabaseHas('chart_of_accounts', [
            'organization_id' => $org->id,
            'account_code' => '2100',
            'account_name' => 'VAT Payable',
        ]);
        $this->assertDatabaseHas('fiscal_periods', [
            'organization_id' => $org->id,
            'period_name' => now()->format('F Y'),
            'status' => 'open',
        ]);
    }
}
