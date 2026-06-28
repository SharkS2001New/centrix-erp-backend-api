<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\OrderNumberAllocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OrderNumberPerOrganizationTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_same_order_num_is_allowed_in_different_organizations(): void
    {
        $demoOrg = Organization::where('company_code', 'DEMO')->firstOrFail();
        $this->assertDatabaseHas('sales', [
            'organization_id' => $demoOrg->id,
            'order_num' => 1,
        ]);

        $secondOrg = $this->createOrganizationWithBranch('ORG2ORD', 'Second Order Org');
        $cashier = $this->createCashierForOrganization($secondOrg['organization'], $secondOrg['branch']);

        Sale::create([
            'order_num' => 1,
            'branch_id' => $secondOrg['branch']->id,
            'organization_id' => $secondOrg['organization']->id,
            'channel' => 'pos',
            'cashier_id' => $cashier->id,
            'status' => 'completed',
            'total_vat' => 0,
            'order_total' => 500,
            'payment_status' => 'paid',
            'amount_paid' => 500,
            'stock_balanced' => 1,
        ]);

        $this->assertDatabaseHas('sales', [
            'organization_id' => $secondOrg['organization']->id,
            'order_num' => 1,
        ]);
        $this->assertSame(2, Sale::query()
            ->where('organization_id', $secondOrg['organization']->id)
            ->where('order_num', '<', OrderNumberAllocator::LEGACY_IMPORTED_ORDER_NUM_MIN)
            ->count());
    }

    public function test_order_num_must_be_unique_within_organization(): void
    {
        $demoOrg = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->expectException(UniqueConstraintViolationException::class);

        Sale::create([
            'order_num' => 1,
            'branch_id' => $admin->branch_id,
            'organization_id' => $demoOrg->id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'total_vat' => 0,
            'order_total' => 100,
            'payment_status' => 'paid',
            'amount_paid' => 100,
            'stock_balanced' => 1,
        ]);
    }

    public function test_allocator_starts_at_one_for_each_organization(): void
    {
        $demoOrg = Organization::where('company_code', 'DEMO')->firstOrFail();
        $secondOrg = $this->createOrganizationWithBranch('ORG2SEQ', 'Second Sequence Org');
        $allocator = app(OrderNumberAllocator::class);

        $this->assertSame(4, $allocator->nextForOrganization((int) $demoOrg->id));
        $this->assertSame(1, $allocator->nextForOrganization((int) $secondOrg['organization']->id));
    }

    /**
     * @return array{organization: Organization, branch: Branch}
     */
    protected function createOrganizationWithBranch(string $companyCode, string $orgName): array
    {
        $organization = Organization::create([
            'company_code' => $companyCode,
            'org_name' => $orgName,
            'org_email' => strtolower($companyCode).'@test.com',
            'primary_tel' => '0700999888',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
        ]);

        $branch = Branch::create([
            'organization_id' => $organization->id,
            'branch_code' => 'HQ',
            'branch_name' => 'Head Office',
            'branch_type' => 'small_shop',
        ]);

        return compact('organization', 'branch');
    }

    protected function createCashierForOrganization(Organization $organization, Branch $branch): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        return User::create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'role_id' => $admin->role_id,
            'username' => 'cashier_'.$organization->company_code,
            'password' => $admin->password,
            'full_name' => 'Cashier '.$organization->company_code,
            'is_active' => true,
        ]);
    }
}
