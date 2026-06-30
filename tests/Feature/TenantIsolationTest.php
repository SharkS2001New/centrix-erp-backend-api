<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_org_wide_user_only_sees_drivers_in_their_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgB = $this->createOrganization('ISOORG2', 'Isolation Org Two');

        $branchB = \App\Models\Branch::create([
            'organization_id' => $orgB->id,
            'branch_code' => 'HQ',
            'branch_name' => 'HQ',
            'branch_type' => 'retail',
        ]);

        Driver::create([
            'branch_id' => $branchB->id,
            'driver_code' => 'DRV-ISO',
            'full_name' => 'Other Org Driver',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/drivers?per_page=100')->assertOk();
        $codes = collect($response->json('data'))->pluck('driver_code');

        $this->assertFalse($codes->contains('DRV-ISO'));
    }

    public function test_org_wide_user_only_sees_vehicles_in_their_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgB = $this->createOrganization('ISOORG3', 'Isolation Org Three');

        $branchB = \App\Models\Branch::create([
            'organization_id' => $orgB->id,
            'branch_code' => 'HQ',
            'branch_name' => 'HQ',
            'branch_type' => 'retail',
        ]);

        Vehicle::create([
            'branch_id' => $branchB->id,
            'vehicle_code' => 'V-ISO',
            'vehicle_name' => 'Other Org Van',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/vehicles?per_page=100')->assertOk();
        $codes = collect($response->json('data'))->pluck('vehicle_code');

        $this->assertFalse($codes->contains('V-ISO'));
    }

    public function test_payment_methods_are_scoped_per_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgB = $this->createOrganization('ISOORG4', 'Isolation Org Four');

        PaymentMethod::create([
            'organization_id' => $orgB->id,
            'method_name' => 'Org B Wallet',
            'method_code' => 'WALLET',
            'requires_reference' => false,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/payment-methods?per_page=100')->assertOk();
        $codes = collect($response->json('data'))->pluck('method_code');

        $this->assertFalse($codes->contains('WALLET'));
    }

    protected function createOrganization(string $companyCode, string $orgName): Organization
    {
        return Organization::create([
            'company_code' => $companyCode,
            'org_name' => $orgName,
            'org_email' => strtolower($companyCode).'@test.com',
            'primary_tel' => '0700444333',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);
    }
}
