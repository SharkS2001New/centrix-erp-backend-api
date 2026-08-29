<?php

namespace Tests\Feature;

use App\Models\MpesaPaybillAccount;
use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MpesaStkPushTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);

        $org = Organization::findOrFail($this->user->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['centrix_payments'] = true;
        $settings = $org->module_settings ?? [];
        $settings['centrix_payments'] = array_merge($settings['centrix_payments'] ?? [], [
            'enable_centrix_payments' => true,
        ]);
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_mpesa_stk' => true,
            'mpesa' => [
                'enable_stk_push' => true,
                'env' => 'live',
                'consumer_key' => 'test-key',
                'consumer_secret' => 'test-secret',
                'shortcode' => '5000072',
                'till_number' => '8881950',
                'passkey' => 'test-passkey',
                'stk_callback_url' => 'http://localhost:8000/api/v1/payments/stk/callback',
            ],
        ]);
        $org->update([
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ]);
        $this->flushOrganizationCache((int) $org->id);

        MpesaPaybillAccount::query()->updateOrCreate(
            [
                'organization_id' => $org->id,
                'primary_short_code' => '5000072',
            ],
            [
                'name' => 'Test Paybill',
                'shortcode' => '5000072',
                'till_number' => '8881950',
                'branch_id' => $this->user->branch_id,
                'is_default' => true,
                'is_active' => true,
                'enable_stk_push' => true,
                'consumer_key' => 'test-key',
                'consumer_secret' => 'test-secret',
                'passkey' => 'test-passkey',
                'stk_callback_url' => 'http://localhost:8000/api/v1/payments/stk/callback',
            ],
        );
    }

    public function test_stk_push_rejects_localhost_callback_url(): void
    {
        $productCode = \App\Models\Product::first()->product_code;
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/sales/carts/{$cartId}/payment/mpesa/stk-push", [
            'phone_number' => '0712345678',
        ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'STK callback URL must be publicly reachable (not localhost).',
            ]);
    }

    public function test_stk_push_rejected_when_disabled_for_organization(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance']['mpesa'] = array_merge($settings['finance']['mpesa'] ?? [], [
            'enable_stk_push' => false,
            'env' => 'live',
            'consumer_key' => 'test-key',
            'consumer_secret' => 'test-secret',
            'shortcode' => '5000072',
            'till_number' => '8881950',
            'passkey' => 'test-passkey',
            'stk_callback_url' => 'https://example.com/api/v1/payments/stk/callback',
        ]);
        $org->update(['module_settings' => $settings]);
        $this->flushOrganizationCache((int) $org->id);

        $productCode = \App\Models\Product::first()->product_code;
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/sales/carts/{$cartId}/payment/mpesa/stk-push", [
            'phone_number' => '0712345678',
        ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'STK push is disabled for this organization. Enable it under Admin → Settings → Finance.',
            ]);
    }

    public function test_stk_push_rejected_when_platform_mpesa_stk_disabled(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_mpesa_stk' => false,
            'mpesa' => array_merge($settings['finance']['mpesa'] ?? [], [
                'enable_stk_push' => true,
                'env' => 'live',
                'consumer_key' => 'test-key',
                'consumer_secret' => 'test-secret',
                'shortcode' => '5000072',
                'till_number' => '8881950',
                'passkey' => 'test-passkey',
                'stk_callback_url' => 'https://example.com/api/v1/payments/stk/callback',
            ]),
        ]);
        $org->update(['module_settings' => $settings]);
        $this->flushOrganizationCache((int) $org->id);

        $productCode = \App\Models\Product::first()->product_code;
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/sales/carts/{$cartId}/payment/mpesa/stk-push", [
            'phone_number' => '0712345678',
        ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'STK push is disabled for this organization. Enable it under Admin → Settings → Finance.',
            ]);
    }

    protected function flushOrganizationCache(int $organizationId): void
    {
        app(\App\Services\Erp\ErpContext::class)->forgetOrganizationCache($organizationId);
    }
}
