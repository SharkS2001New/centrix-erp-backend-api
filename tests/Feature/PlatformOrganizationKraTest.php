<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformOrganizationKraTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_access_kra_routes_when_acting_as_organization(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'KRAORG',
            'org_name' => 'KRA Org Ltd',
            'org_email' => 'kra@org.com',
            'primary_tel' => '0711000010',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ['sales' => true, 'sales.backend' => true, 'admin' => true],
            'admin_username' => 'kra_admin',
            'admin_email' => 'kra@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'KRA Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $this->getJson("/api/v1/admin/organizations/{$orgId}/kra-responses?per_page=5")
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson("/api/v1/admin/organizations/{$orgId}/kra/device-status")
            ->assertOk()
            ->assertJsonStructure(['enabled', 'fiscalization_active', 'message']);
    }
}
