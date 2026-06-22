<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformOrganizationSettingsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_read_and_update_tenant_settings_when_acting_as_organization(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'SETORG',
            'org_name' => 'Settings Org Ltd',
            'org_email' => 'set@org.com',
            'primary_tel' => '0711000001',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ['sales' => true, 'sales.pos' => true, 'admin' => false],
            'admin_username' => 'set_admin',
            'admin_email' => 'set@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Set Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $this->getJson("/api/v1/admin/organizations/{$orgId}/settings/general")
            ->assertOk()
            ->assertJsonStructure(['general']);

        $this->patchJson("/api/v1/admin/organizations/{$orgId}/settings/general", [
            'currency' => 'USD',
            'language' => 'en',
        ])->assertOk()
            ->assertJsonPath('general.currency', 'USD');

        $org = Organization::findOrFail($orgId);
        $this->assertSame('USD', $org->module_settings['general']['currency'] ?? null);
    }

    public function test_tenant_admin_cannot_use_platform_settings_proxy(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->getJson('/api/v1/admin/organizations/'.$orgAdmin->organization_id.'/settings/general')
            ->assertForbidden();
    }

    public function test_tenant_org_admin_cannot_use_erp_settings_routes(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->getJson('/api/v1/erp/settings/sales')
            ->assertForbidden()
            ->assertJsonPath('message', 'Organization settings are managed by the platform administrator.');
    }

    public function test_organization_index_includes_administration_enabled_flag(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/admin/organizations')->assertOk();

        $demo = collect($response->json('data'))->firstWhere('company_code', 'DEMO');
        $this->assertNotNull($demo);
        $this->assertArrayHasKey('administration_enabled', $demo);
    }

    public function test_super_admin_can_list_organizations_when_provisioning_disabled(): void
    {
        config(['erp.allow_org_provisioning' => false]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/admin/organizations')->assertOk();
    }
}
