<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
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
            'use_platform_ai' => false,
            'api_key' => 'sk-test-org-key-123456',
        ])->assertOk()
            ->assertJsonPath('platform_enabled', true)
            ->assertJsonPath('available', true)
            ->assertJsonPath('credential_source', 'org');
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
                'gemini_model' => 'gemini-3.6-flash',
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
        $this->assertSame('gemini-3.6-flash', $runtime['model']);

        Sanctum::actingAs($superAdmin);

        $this->getJson("/api/v1/admin/organizations/{$orgId}/settings/ai")
            ->assertOk()
            ->assertJsonPath('use_platform_gemini', true)
            ->assertJsonPath('available', true)
            ->assertJsonPath('credential_source', 'platform_gemini')
            ->assertJsonPath('provider', 'gemini');

        // Stored org key does not override platform AI while use_platform_ai remains true.
        $this->patchJson("/api/v1/admin/organizations/{$orgId}/settings/ai", [
            'provider' => 'openai',
            'api_key' => 'sk-org-override-key',
        ])->assertOk()
            ->assertJsonPath('use_platform_ai', true)
            ->assertJsonPath('credential_source', 'platform_gemini')
            ->assertJsonPath('has_org_api_key', true);

        $stillPlatform = Organization::findOrFail($orgId);
        $resolvedPlatform = \App\Services\Ai\AiSettingsResolver::resolveRuntimeForOrganization($stillPlatform);
        $this->assertNotNull($resolvedPlatform);
        $this->assertSame('gemini', $resolvedPlatform['provider']);
        $this->assertSame('AIza-platform-gemini-test-key', $resolvedPlatform['api_key']);

        // Opt out of platform AI to use the organization key instead.
        $this->patchJson("/api/v1/admin/organizations/{$orgId}/settings/ai", [
            'use_platform_ai' => false,
        ])->assertOk()
            ->assertJsonPath('use_platform_ai', false)
            ->assertJsonPath('credential_source', 'org');

        $fresh = Organization::findOrFail($orgId);
        $this->assertTrue((bool) ($fresh->module_settings['ai']['use_platform_gemini'] ?? false));
        $resolved = \App\Services\Ai\AiSettingsResolver::resolveRuntimeForOrganization($fresh);
        $this->assertNotNull($resolved);
        $this->assertSame('openai', $resolved['provider']);
        $this->assertSame('sk-org-override-key', $resolved['api_key']);
    }

    public function test_platform_can_offer_free_openai_instead_of_gemini(): void
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
                'free_ai_provider' => 'openai',
                'api_key' => 'sk-platform-openai-free-key',
                'model' => 'gpt-4o-mini',
                'gemini_api_key' => 'AIza-should-not-be-used',
            ],
        );
        $platformOrg->update(['module_settings' => $moduleSettings]);
        \App\Services\Ai\AiSettingsResolver::platformOrganization(refresh: true);

        $this->assertSame('openai', \App\Services\Ai\AiSettingsResolver::platformFreeAiProvider());

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'AIOAI',
            'org_name' => 'AI OpenAI Free Org',
            'org_email' => 'aioai@org.com',
            'primary_tel' => '0711000066',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'sales_platform' => [
                'enable_ai' => true,
                'use_platform_gemini' => true,
            ],
            'admin_username' => 'aioai_admin',
            'admin_email' => 'aioai@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'AI OpenAI Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');
        $org = Organization::findOrFail($orgId);
        $this->assertSame('openai', $org->module_settings['ai']['provider'] ?? null);

        $runtime = \App\Services\Ai\AiSettingsResolver::resolveRuntimeForOrganization($org);
        $this->assertNotNull($runtime);
        $this->assertSame('openai', $runtime['provider']);
        $this->assertSame('sk-platform-openai-free-key', $runtime['api_key']);

        $this->getJson("/api/v1/admin/organizations/{$orgId}/settings/ai")
            ->assertOk()
            ->assertJsonPath('credential_source', 'platform_openai')
            ->assertJsonPath('free_ai_provider', 'openai')
            ->assertJsonPath('provider', 'openai');
    }

    public function test_platform_tools_use_gemini_when_enabled_without_openai(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $platformOrg = Organization::query()
            ->where('company_code', config('erp.platform_company_code', 'PLATFORM'))
            ->firstOrFail();

        $moduleSettings = $platformOrg->module_settings ?? [];
        $moduleSettings['platform_ai_training'] = [
            'enabled' => true,
            'free_ai_provider' => 'gemini',
            'gemini_api_key' => 'AIza-platform-tools-gemini',
            'gemini_model' => 'gemini-3.6-flash',
            'api_key' => '',
        ];
        $platformOrg->update(['module_settings' => $moduleSettings]);
        \App\Services\Ai\AiSettingsResolver::platformOrganization(refresh: true);

        $runtime = \App\Services\Ai\AiSettingsResolver::resolveRuntimeForPlatformTraining();
        $this->assertNotNull($runtime);
        $this->assertSame('gemini', $runtime['provider']);
        $this->assertSame('AIza-platform-tools-gemini', $runtime['api_key']);
        $this->assertSame('gemini-3.6-flash', $runtime['model']);

        $this->getJson('/api/v1/admin/ai-training/settings')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('tools_available', true)
            ->assertJsonPath('provider', 'gemini')
            ->assertJsonPath('free_ai_provider', 'gemini');
    }

    public function test_platform_can_test_gemini_credentials(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'Hello, Centrix ERP!']],
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 8,
                    'candidatesTokenCount' => 6,
                    'totalTokenCount' => 14,
                ],
            ], 200),
        ]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $this->postJson('/api/v1/admin/ai-training/test-credentials', [
            'provider' => 'gemini',
            'gemini_api_key' => 'AIza-test-live-key',
            'gemini_model' => 'gemini-3.6-flash',
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('provider', 'gemini')
            ->assertJsonPath('model', 'gemini-3.6-flash')
            ->assertJsonPath('reply', 'Hello, Centrix ERP!');
    }

    public function test_org_admin_can_test_own_ai_credentials(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Hello from org OpenAI'],
                ]],
                'usage' => [
                    'prompt_tokens' => 8,
                    'completion_tokens' => 6,
                    'total_tokens' => 14,
                ],
            ], 200),
        ]);

        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'AITST',
            'org_name' => 'AI Test Org',
            'org_email' => 'aitst@org.com',
            'primary_tel' => '0711000055',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'sales_platform' => ['enable_ai' => true],
            'admin_username' => 'aitst_admin',
            'admin_email' => 'aitst@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'AI Test Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/v1/admin/organizations/{$orgId}/settings/ai/test-credentials", [
            'provider' => 'openai',
            'use_platform_ai' => false,
            'api_key' => 'sk-org-test-key',
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('provider', 'openai')
            ->assertJsonPath('reply', 'Hello from org OpenAI');
    }
}
