<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsappConfig;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformWhatsappControlTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_disable_whatsapp_for_organization(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'WANO',
            'org_name' => 'WhatsApp Disabled Org',
            'org_email' => 'wa@org.com',
            'primary_tel' => '0711000077',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'sales_platform' => ['enable_whatsapp_orders' => false],
            'admin_username' => 'wa_admin',
            'admin_email' => 'wa@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'WA Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $this->getJson("/api/v1/admin/organizations/{$orgId}")
            ->assertOk()
            ->assertJsonPath('sales_platform.enable_whatsapp_orders', false);

        $orgAdmin = User::where('username', 'wa_admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->patchJson('/api/v1/erp/settings/whatsapp', [
            'enabled' => true,
            'phone_number_id' => '123456789',
            'access_token' => 'EAA-test-token',
        ])->assertNotFound();

        $this->getJson('/api/v1/erp/settings/whatsapp')->assertNotFound();

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('platform_whatsapp_enabled', false)
            ->assertJsonPath('whatsapp_orders.platform_enabled', false)
            ->assertJsonPath('whatsapp_orders.enabled', false);

        $org = Organization::findOrFail($orgId);
        $this->assertFalse($org->module_settings['whatsapp']['enable_whatsapp_orders'] ?? true);
    }

    public function test_super_admin_can_re_enable_whatsapp_for_organization(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'WAYES',
            'org_name' => 'WhatsApp Enabled Org',
            'org_email' => 'wayes@org.com',
            'primary_tel' => '0711000066',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'sales_platform' => ['enable_whatsapp_orders' => false],
            'admin_username' => 'wayes_admin',
            'admin_email' => 'wayes@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'WA Yes Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $this->patchJson("/api/v1/admin/organizations/{$orgId}", [
            'sales_platform' => ['enable_whatsapp_orders' => true],
        ])->assertOk()
            ->assertJsonPath('sales_platform.enable_whatsapp_orders', true);

        $orgAdmin = User::where('username', 'wayes_admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->getJson('/api/v1/erp/settings/whatsapp')
            ->assertOk()
            ->assertJsonPath('platform_enabled', true)
            ->assertJsonStructure(['webhook_url', 'settings']);

        $this->patchJson('/api/v1/erp/settings/whatsapp', [
            'enabled' => true,
            'phone_number_id' => '987654321',
            'access_token' => 'EAA-test-org-token',
            'bot_user_id' => $orgAdmin->id,
        ])->assertOk()
            ->assertJsonPath('platform_enabled', true)
            ->assertJsonPath('settings.enabled', true)
            ->assertJsonPath('configured', true);
    }

    public function test_org_admin_cannot_change_platform_whatsapp_gate(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'WAGATE',
            'org_name' => 'WA Gate Org',
            'org_email' => 'wagate@org.com',
            'primary_tel' => '0711000055',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'sales_platform' => ['enable_whatsapp_orders' => true],
            'admin_username' => 'wagate_admin',
            'admin_email' => 'wagate@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'WA Gate Admin',
        ])->assertCreated();

        $orgAdmin = User::where('username', 'wagate_admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->patchJson('/api/v1/erp/settings/whatsapp', [
            'enable_whatsapp_orders' => false,
            'enabled' => true,
            'phone_number_id' => 'gate-phone-id',
            'access_token' => 'EAA-gate-token',
            'bot_user_id' => $orgAdmin->id,
        ])->assertOk()
            ->assertJsonPath('platform_enabled', true);

        $org = Organization::findOrFail($create->json('organization.id'));
        $this->assertTrue($org->fresh()->module_settings['whatsapp']['enable_whatsapp_orders'] ?? false);
    }

    public function test_super_admin_can_manage_platform_webhook_settings(): void
    {
        $platformOrg = Organization::where('company_code', config('erp.platform_company_code', 'PLATFORM'))->first();
        if (! $platformOrg) {
            $platformOrg = Organization::factory()->create([
                'company_code' => config('erp.platform_company_code', 'PLATFORM'),
                'org_name' => 'Platform Administration',
            ]);
        }

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/admin/whatsapp/settings')
            ->assertOk()
            ->assertJsonPath('scope', 'platform')
            ->assertJsonStructure(['webhook_url', 'webhook_verify_token_set', 'graph_api_version']);

        $this->patchJson('/api/v1/admin/whatsapp/settings', [
            'webhook_verify_token' => 'centrix-verify-secret',
            'graph_api_version' => 'v21.0',
        ])->assertOk()
            ->assertJsonPath('webhook_verify_token_set', true)
            ->assertJsonPath('graph_api_version', 'v21.0');

        $this->get('/api/v1/webhooks/whatsapp', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'centrix-verify-secret',
            'hub_challenge' => 'challenge-token-123',
        ])->assertOk()
            ->assertSee('challenge-token-123');

        $this->get('/api/v1/webhooks/whatsapp', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'wrong-token',
            'hub_challenge' => 'challenge-token-123',
        ])->assertForbidden();
    }

    public function test_inbound_webhook_routes_by_phone_number_id(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'MOON',
            'org_name' => 'Moon Trading',
            'org_email' => 'moon@org.com',
            'primary_tel' => '0711000044',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'sales_platform' => ['enable_whatsapp_orders' => true],
            'admin_username' => 'moon_admin',
            'admin_email' => 'moon@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Moon Admin',
        ])->assertCreated();

        $orgId = (int) $create->json('organization.id');
        $orgAdmin = User::where('username', 'moon_admin')->firstOrFail();

        Sanctum::actingAs($orgAdmin);

        $this->patchJson('/api/v1/erp/settings/whatsapp', [
            'enabled' => true,
            'phone_number_id' => 'moon-phone-id-001',
            'access_token' => 'EAA-moon-token',
            'bot_user_id' => $orgAdmin->id,
            'display_phone' => '+254700000001',
        ])->assertOk();

        $this->assertDatabaseHas('whatsapp_configs', [
            'organization_id' => $orgId,
            'phone_number_id' => 'moon-phone-id-001',
            'is_active' => true,
        ]);

        $row = WhatsappConfig::query()->where('organization_id', $orgId)->first();
        $this->assertNotNull($row);
        $this->assertSame('moon-phone-id-001', $row->phone_number_id);
    }

    public function test_platform_admin_can_preview_without_org_bot_user(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'WAPRV',
            'org_name' => 'WhatsApp Preview Org',
            'org_email' => 'waprev@org.com',
            'primary_tel' => '0711000033',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'sales_platform' => ['enable_whatsapp_orders' => true],
            'admin_username' => 'waprev_admin',
            'admin_email' => 'waprev@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'WA Preview Admin',
        ])->assertCreated();

        $orgId = (int) $create->json('organization.id');

        $context = $this->getJson('/api/v1/admin/whatsapp/preview/context?organization_id='.$orgId)
            ->assertOk()
            ->assertJsonPath('preview_ready', true)
            ->assertJsonPath('using_platform_admin_bot', true)
            ->assertJsonPath('preview_bot_user.username', $superAdmin->username)
            ->json();

        $this->assertSame('platform_admin', $context['preview_bot_user']['source'] ?? null);

        $this->postJson('/api/v1/admin/whatsapp/preview/simulate', [
            'organization_id' => $orgId,
            'message' => 'HI',
        ])->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonStructure(['reply', 'session', 'notice']);
    }
}
