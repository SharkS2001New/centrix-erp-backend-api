<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformAiControlTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_disable_ai_for_organization(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'AINO',
            'org_name' => 'AI Disabled Org',
            'org_email' => 'ai@org.com',
            'primary_tel' => '0711000099',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'sales_platform' => ['enable_ai' => false],
            'admin_username' => 'ai_admin',
            'admin_email' => 'ai@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'AI Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $show = $this->getJson("/api/v1/admin/organizations/{$orgId}")
            ->assertOk()
            ->assertJsonPath('sales_platform.enable_ai', false);

        $orgAdmin = User::where('username', 'ai_admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->patchJson('/api/v1/erp/settings/ai', [
            'enabled' => true,
            'api_key' => 'sk-test-org-key-123456',
        ])->assertNotFound();

        $this->getJson('/api/v1/erp/settings/ai')->assertNotFound();

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('platform_ai_enabled', false)
            ->assertJsonPath('ai_assistant.platform_enabled', false)
            ->assertJsonPath('ai_assistant.enabled', false);

        $this->getJson('/api/v1/ai/status')
            ->assertOk()
            ->assertJsonPath('platform_enabled', false)
            ->assertJsonPath('enabled', false);

        $org = Organization::findOrFail($orgId);
        $this->assertFalse($org->module_settings['ai']['enable_ai'] ?? true);
    }

    public function test_super_admin_can_re_enable_ai_for_organization(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'AIYES',
            'org_name' => 'AI Enabled Org',
            'org_email' => 'aiyes@org.com',
            'primary_tel' => '0711000088',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'sales_platform' => ['enable_ai' => false],
            'admin_username' => 'aiyes_admin',
            'admin_email' => 'aiyes@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'AI Yes Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $this->patchJson("/api/v1/admin/organizations/{$orgId}", [
            'sales_platform' => ['enable_ai' => true],
        ])->assertOk()
            ->assertJsonPath('sales_platform.enable_ai', true);

        $orgAdmin = User::where('username', 'aiyes_admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->patchJson('/api/v1/erp/settings/ai', [
            'enabled' => true,
            'api_key' => 'sk-test-org-key-123456',
        ])->assertOk()
            ->assertJsonPath('platform_enabled', true)
            ->assertJsonPath('settings.enabled', true);
    }
}
