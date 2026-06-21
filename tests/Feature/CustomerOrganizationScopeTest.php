<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CustomerOrganizationScopeTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_customer_routes_are_scoped_to_the_authenticated_users_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgA = Organization::findOrFail($admin->organization_id);

        $orgB = Organization::create([
            'company_code' => 'OTHERTEN',
            'org_name' => 'Other Tenant Ltd',
            'org_email' => 'other@example.com',
            'primary_tel' => '0700000001',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => $orgA->enabled_modules,
            'module_settings' => $orgA->module_settings,
        ]);

        $branchB = Branch::create([
            'organization_id' => $orgB->id,
            'branch_code' => 'HQ',
            'branch_name' => 'Other HQ',
            'branch_type' => 'retail',
            'branch_phone' => '0700000001',
            'branch_address' => 'Nairobi',
        ]);

        $role = Role::query()->firstOrFail();
        $userB = User::create([
            'organization_id' => $orgB->id,
            'branch_id' => $branchB->id,
            'role_id' => $role->id,
            'username' => 'other_tenant_user',
            'password' => Hash::make('password'),
            'full_name' => 'Other Tenant User',
            'is_active' => true,
            'access_scope' => 'org',
        ]);

        $customerA = Customer::query()
            ->where('organization_id', $orgA->id)
            ->firstOrFail();

        $customerB = Customer::create([
            'organization_id' => $orgB->id,
            'branch_id' => $branchB->id,
            'customer_num' => $customerA->customer_num,
            'customer_name' => 'Other Org Customer',
            'customer_type' => 'retail',
            'phone_number' => '0700111222',
        ]);

        Sanctum::actingAs($userB);

        $this->getJson("/api/v1/customers/{$customerA->customer_num}")
            ->assertNotFound();

        $this->patchJson("/api/v1/customers/{$customerA->customer_num}", [
            'customer_name' => 'Hijacked',
        ])->assertNotFound();

        $this->getJson("/api/v1/customers/{$customerB->customer_num}")
            ->assertOk()
            ->assertJsonPath('customer_name', 'Other Org Customer');
    }
}
