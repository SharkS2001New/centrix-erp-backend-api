<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use App\Services\Customers\CustomerNumberAllocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class TenantScopedSequentialNumbersTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_two_organizations_can_share_the_same_customer_num(): void
    {
        $orgA = Organization::where('company_code', 'DEMO')->firstOrFail();
        $customerA = Customer::query()->where('organization_id', $orgA->id)->firstOrFail();

        $orgB = $this->createOrganization('CUSTORG2', 'Customer Org Two');
        $branchB = Branch::create([
            'organization_id' => $orgB->id,
            'branch_code' => 'HQ',
            'branch_name' => 'HQ',
            'branch_type' => 'retail',
        ]);

        $customerB = Customer::create([
            'organization_id' => $orgB->id,
            'branch_id' => $branchB->id,
            'customer_num' => $customerA->customer_num,
            'customer_name' => 'Shared Number Customer',
            'customer_type' => 'debtor',
            'phone_number' => '0700123456',
            'created_by' => User::where('username', 'admin')->value('id'),
        ]);

        $this->assertDatabaseHas('customers', [
            'organization_id' => $orgB->id,
            'customer_num' => $customerA->customer_num,
        ]);
        $this->assertNotSame($customerA->id, $customerB->id);
    }

    public function test_customer_num_must_be_unique_within_organization(): void
    {
        $orgA = Organization::where('company_code', 'DEMO')->firstOrFail();
        $existing = Customer::query()->where('organization_id', $orgA->id)->firstOrFail();

        $this->expectException(UniqueConstraintViolationException::class);

        Customer::create([
            'organization_id' => $orgA->id,
            'branch_id' => $existing->branch_id,
            'customer_num' => $existing->customer_num,
            'customer_name' => 'Duplicate Customer',
            'customer_type' => 'debtor',
            'phone_number' => '0799999999',
            'created_by' => User::where('username', 'admin')->value('id'),
        ]);
    }

    public function test_customer_allocator_starts_at_one_for_new_organization(): void
    {
        $orgA = Organization::where('company_code', 'DEMO')->firstOrFail();
        $orgB = $this->createOrganization('CUSTORG3', 'Customer Org Three');
        $allocator = app(CustomerNumberAllocator::class);

        $this->assertGreaterThan(0, $allocator->nextForOrganization((int) $orgA->id));
        $this->assertSame(1, $allocator->nextForOrganization((int) $orgB->id));
    }

    public function test_two_organizations_can_share_ar_invoice_number(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgA = Organization::findOrFail($admin->organization_id);
        $customerA = Customer::query()->where('organization_id', $orgA->id)->firstOrFail();

        $saleA = Sale::query()->where('organization_id', $orgA->id)->firstOrFail();

        CustomerInvoice::create([
            'invoice_number' => 'AR-1',
            'sale_id' => $saleA->id,
            'customer_num' => $customerA->customer_num,
            'branch_id' => $saleA->branch_id,
            'organization_id' => $orgA->id,
            'created_by' => $admin->id,
            'invoice_date' => now()->toDateString(),
            'invoice_total' => 100,
            'amount_paid' => 0,
        ]);

        $orgB = $this->createOrganization('INVORG2', 'Invoice Org Two');
        $branchB = Branch::create([
            'organization_id' => $orgB->id,
            'branch_code' => 'HQ',
            'branch_name' => 'HQ',
            'branch_type' => 'retail',
        ]);
        $customerB = Customer::create([
            'organization_id' => $orgB->id,
            'branch_id' => $branchB->id,
            'customer_num' => 1,
            'customer_name' => 'Invoice Org Customer',
            'customer_type' => 'debtor',
            'phone_number' => '0700333444',
            'created_by' => $admin->id,
        ]);
        $saleB = Sale::create([
            'order_num' => 1,
            'branch_id' => $branchB->id,
            'organization_id' => $orgB->id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'total_vat' => 0,
            'order_total' => 200,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'stock_balanced' => 1,
        ]);

        CustomerInvoice::create([
            'invoice_number' => 'AR-1',
            'sale_id' => $saleB->id,
            'customer_num' => $customerB->customer_num,
            'branch_id' => $branchB->id,
            'organization_id' => $orgB->id,
            'created_by' => $admin->id,
            'invoice_date' => now()->toDateString(),
            'invoice_total' => 200,
            'amount_paid' => 0,
        ]);

        $this->assertDatabaseHas('customer_invoices', [
            'organization_id' => $orgA->id,
            'invoice_number' => 'AR-1',
        ]);
        $this->assertDatabaseHas('customer_invoices', [
            'organization_id' => $orgB->id,
            'invoice_number' => 'AR-1',
        ]);
    }

    protected function createOrganization(string $companyCode, string $orgName): Organization
    {
        return Organization::create([
            'company_code' => $companyCode,
            'org_name' => $orgName,
            'org_email' => strtolower($companyCode).'@test.com',
            'primary_tel' => '0700555666',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);
    }
}
