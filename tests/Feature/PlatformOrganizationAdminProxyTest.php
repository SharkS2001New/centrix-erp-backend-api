<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vat;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformOrganizationAdminProxyTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function provisionOrgWithoutAdmin(): int
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'PLTADM',
            'org_name' => 'Platform Admin Org',
            'org_email' => 'platadm@org.com',
            'primary_tel' => '0711000099',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => [
                'sales' => true,
                'inventory' => true,
                'admin' => false,
            ],
            'admin_username' => 'plat_admin',
            'admin_email' => 'platadm@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Platform Admin User',
        ])->assertCreated();

        return (int) $create->json('organization.id');
    }

    public function test_super_admin_can_list_vats_via_platform_org_proxy(): void
    {
        $orgId = $this->provisionOrgWithoutAdmin();

        Vat::query()->create([
            'vat_code' => 'PLT'.substr(uniqid(), -4),
            'vat_name' => 'Standard',
            'vat_percentage' => 16,
            'is_active' => true,
            'created_by' => User::where('username', 'superadmin')->firstOrFail()->id,
        ]);

        $this->getJson("/api/v1/admin/organizations/{$orgId}/vats")
            ->assertOk()
            ->assertJsonPath('data.0.vat_code', 'V');
    }

    public function test_super_admin_can_list_kra_responses_via_platform_org_proxy(): void
    {
        $orgId = $this->provisionOrgWithoutAdmin();

        $this->getJson("/api/v1/admin/organizations/{$orgId}/kra-responses")
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_super_admin_can_read_kra_device_status_via_platform_org_proxy(): void
    {
        $orgId = $this->provisionOrgWithoutAdmin();

        $this->getJson("/api/v1/admin/organizations/{$orgId}/kra/device-status")
            ->assertOk()
            ->assertJsonStructure(['enabled', 'reachable', 'message']);
    }
}
