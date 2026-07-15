<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SaleCustomerOrganizationScopeTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_eager_loaded_customer_matches_sale_organization_when_customer_num_is_shared(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgA = Organization::findOrFail($admin->organization_id);

        $sharedNum = 990034;
        Customer::query()->where('organization_id', $orgA->id)->where('customer_num', $sharedNum)->delete();

        $customerA = Customer::query()->create([
            'organization_id' => $orgA->id,
            'branch_id' => $admin->branch_id,
            'customer_num' => $sharedNum,
            'customer_name' => 'Wathoita Cereal Shop',
            'customer_type' => 'regular',
            'phone_number' => '0700990034',
            'created_by' => $admin->id,
        ]);

        $orgB = Organization::create([
            'company_code' => 'CUSTSHARE',
            'org_name' => 'Customer Share Org',
            'org_email' => 'custshare@test.com',
            'primary_tel' => '0700555666',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);
        $branchB = Branch::create([
            'organization_id' => $orgB->id,
            'branch_code' => 'HQ',
            'branch_name' => 'HQ',
            'branch_type' => 'retail',
        ]);
        Customer::query()->create([
            'organization_id' => $orgB->id,
            'branch_id' => $branchB->id,
            'customer_num' => $sharedNum,
            'customer_name' => 'honey cup',
            'customer_type' => 'regular',
            'phone_number' => '0700990035',
            'created_by' => $admin->id,
        ]);

        $sale = Sale::query()->create([
            'order_num' => 990034,
            'branch_id' => $admin->branch_id,
            'organization_id' => $orgA->id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'customer_num' => $sharedNum,
            'customer_name_override' => null,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'order_total' => 9200,
            'amount_paid' => 0,
            'created_at' => now(),
        ]);

        $loaded = Sale::query()
            ->with(['customer:customer_num,customer_name,organization_id'])
            ->findOrFail($sale->id);

        $this->assertNotNull($loaded->customer);
        $this->assertSame((int) $customerA->id, (int) $loaded->customer->id);
        $this->assertSame('Wathoita Cereal Shop', $loaded->customer->customer_name);
        $this->assertSame((int) $orgA->id, (int) $loaded->customer->organization_id);
    }

    public function test_sales_list_api_returns_organization_scoped_customer_name(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $admin->organization_id],
            [
                'status' => 'active',
                'current_period_start' => now()->subMonth()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'renewal_price' => 0,
                'amount' => 0,
                'currency' => 'KES',
            ],
        );

        $sharedNum = 990035;
        Customer::query()->where('organization_id', $admin->organization_id)->where('customer_num', $sharedNum)->delete();

        Customer::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'customer_num' => $sharedNum,
            'customer_name' => 'Wathoita Cereal Shop',
            'customer_type' => 'regular',
            'phone_number' => '0700990036',
            'created_by' => $admin->id,
        ]);

        $orgB = Organization::create([
            'company_code' => 'CUSTAPI',
            'org_name' => 'Customer API Org',
            'org_email' => 'custapi@test.com',
            'primary_tel' => '0700555777',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);
        $branchB = Branch::create([
            'organization_id' => $orgB->id,
            'branch_code' => 'HQ',
            'branch_name' => 'HQ',
            'branch_type' => 'retail',
        ]);
        Customer::query()->create([
            'organization_id' => $orgB->id,
            'branch_id' => $branchB->id,
            'customer_num' => $sharedNum,
            'customer_name' => 'honey cup',
            'customer_type' => 'regular',
            'phone_number' => '0700990037',
            'created_by' => $admin->id,
        ]);

        $sale = Sale::query()->create([
            'order_num' => 990035,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'customer_num' => $sharedNum,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'order_total' => 9200,
            'amount_paid' => 0,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/sales?q=990035&per_page=50');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $sale->id);
        $this->assertNotNull($row);
        $this->assertSame('Wathoita Cereal Shop', $row['customer']['customer_name'] ?? null);
        $this->assertSame((int) $admin->organization_id, (int) ($row['customer']['organization_id'] ?? 0));
    }
}
