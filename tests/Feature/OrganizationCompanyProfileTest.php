<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OrganizationCompanyProfileTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_org_admin_can_load_company_profile(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $response = $this->getJson('/api/v1/erp/organization/profile');

        $response->assertOk()
            ->assertJsonPath('organization.company_code', 'DEMO')
            ->assertJsonPath('organization.org_name', 'Demo Wholesalers Ltd')
            ->assertJsonPath('organization.primary_tel', '0700111222')
            ->assertJsonPath('organization.org_address', 'Industrial Area, Nairobi, KE');
    }

    public function test_org_admin_can_update_company_profile_via_profile_endpoint(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->patchJson('/api/v1/erp/organization/profile', [
            'org_name' => 'Updated Demo Name',
            'primary_tel' => '0711222333',
            'org_address' => 'Updated address line',
        ])->assertOk()
            ->assertJsonPath('organization.org_name', 'Updated Demo Name')
            ->assertJsonPath('organization.primary_tel', '0711222333')
            ->assertJsonPath('organization.org_address', 'Updated address line');
    }
}
