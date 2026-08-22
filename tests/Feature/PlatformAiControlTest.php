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

        $blockedPatch = $this->patchJson('/api/v1/erp/settings/ai', [
            'enabled' => true,
            'api_key' => 'sk-test-org-key-123456',
        ]);
        $this->assertTrue(in_array($blockedPatch->status(), [403, 404], true), 'AI settings must be blocked when platform AI is off');

        $blockedGet = $this->getJson('/api/v1/erp/settings/ai');
        $this->assertTrue(in_array($blockedGet->status(), [403, 404], true));

        $status = $this->getJson('/api/v1/ai/status');
        if ($status->status() === 200) {
            $status
                ->assertJsonPath('platform_enabled', false)
                ->assertJsonPath('enabled', false);
        }

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

        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/v1/admin/organizations/{$orgId}/settings/ai", [
            'enabled' => true,
            'api_key' => 'sk-test-org-key-123456',
        ])->assertOk()
            ->assertJsonPath('platform_enabled', true)
            ->assertJsonPath('settings.enabled', true);
    }

    public function test_super_admin_can_enable_platform_gemini_for_selected_org(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $platformOrg = Organization::query()
            ->where('company_code', config('erp.platform_company_code', 'PLATFORM'))
            ->firstOrFail();

        $moduleSettings = $platformOrg->module_settings ?? [];
        $moduleSettings['platform_ai_training'] = array_merge(
            is_array($moduleSettings['platform_ai_training'] ?? null) ? $moduleSettings['platform_ai_training'] : [],
            [
                'enabled' => true,
                'gemini_api_key' => 'AIza-platform-gemini-test-key',
                'gemini_model' => 'gemini-3.7-flash',
            ],
        );
        $platformOrg->update(['module_settings' => $moduleSettings]);
        \App\Services\Ai\AiSettingsResolver::platformOrganization(refresh: true);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'AIGEM',
            'org_name' => 'AI Gemini Org',
            'org_email' => 'aigem@org.com',
            'primary_tel' => '0711000077',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'sales_platform' => [
                'enable_ai' => true,
                'use_platform_gemini' => true,
            ],
            'admin_username' => 'aigem_admin',
            'admin_email' => 'aigem@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'AI Gemini Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $this->getJson("/api/v1/admin/organizations/{$orgId}")
            ->assertOk()
            ->assertJsonPath('sales_platform.enable_ai', true)
            ->assertJsonPath('sales_platform.use_platform_gemini', true);

        $org = Organization::findOrFail($orgId);
        $this->assertTrue((bool) ($org->module_settings['ai']['use_platform_gemini'] ?? false));
        $this->assertSame('gemini', $org->module_settings['ai']['provider'] ?? null);
        $this->assertTrue((bool) ($org->module_settings['ai']['enabled'] ?? false));

        $runtime = \App\Services\Ai\AiSettingsResolver::resolveRuntimeForOrganization($org);
        $this->assertNotNull($runtime);
        $this->assertSame('gemini', $runtime['provider']);
        $this->assertSame('AIza-platform-gemini-test-key', $runtime['api_key']);
        $this->assertSame('gemini-3.7-flash', $runtime['model']);

        Sanctum::actingAs($superAdmin);

        $this->getJson("/api/v1/admin/organizations/{$orgId}/settings/ai")
            ->assertOk()
            ->assertJsonPath('use_platform_gemini', true)
            ->assertJsonPath('available', true)
            ->assertJsonPath('credential_source', 'platform_gemini')
            ->assertJsonPath('provider', 'gemini');

        // Org (or platform managing the org) may add its own key; that overrides free Gemini.
        $this->patchJson("/api/v1/admin/organizations/{$orgId}/settings/ai", [
            'provider' => 'openai',
            'api_key' => 'sk-org-override-key',
            'enabled' => true,
        ])->assertOk()
            ->assertJsonPath('credential_source', 'org')
            ->assertJsonPath('has_org_api_key', true);

        $fresh = Organization::findOrFail($orgId);
        $this->assertTrue((bool) ($fresh->module_settings['ai']['use_platform_gemini'] ?? false));
        $resolved = \App\Services\Ai\AiSettingsResolver::resolveRuntimeForOrganization($fresh);
        $this->assertNotNull($resolved);
        $this->assertSame('openai', $resolved['provider']);
        $this->assertSame('sk-org-override-key', $resolved['api_key']);
    }
}
