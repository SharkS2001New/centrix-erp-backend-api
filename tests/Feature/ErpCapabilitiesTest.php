<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ErpCapabilitiesTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_capabilities_masks_mpesa_secrets(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $org = Organization::findOrFail($user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'mpesa' => [
                'env' => 'sandbox',
                'consumer_key' => 'demo-key',
                'consumer_secret' => 'super-secret',
                'shortcode' => '600000',
                'till_number' => '600001',
                'passkey' => 'demo-passkey',
                'stk_callback_url' => 'https://example.com/api/v1/payments/stk/callback',
            ],
        ]);
        $org->update(['module_settings' => $settings]);

        $response = $this->getJson('/api/v1/erp/capabilities')->assertOk();
        $mpesa = $response->json('module_settings.finance.mpesa');

        $this->assertSame('demo-key', $mpesa['consumer_key']);
        $this->assertSame('********', $mpesa['consumer_secret']);
        $this->assertSame('********', $mpesa['passkey']);
        $this->assertNotSame('super-secret', $mpesa['consumer_secret']);
        $this->assertNotSame('demo-passkey', $mpesa['passkey']);
    }

    public function test_allow_org_provisioning_reflects_current_config_not_stale_cache(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        config(['erp.allow_org_provisioning' => false]);
        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('allow_org_provisioning', false);

        config(['erp.allow_org_provisioning' => true]);
        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('allow_org_provisioning', true);
    }
}
