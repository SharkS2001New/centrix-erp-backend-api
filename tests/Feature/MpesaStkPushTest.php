<?php

namespace Tests\Feature;

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
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'mpesa' => [
                'env' => 'live',
                'consumer_key' => 'test-key',
                'consumer_secret' => 'test-secret',
                'shortcode' => '5000072',
                'till_number' => '8881950',
                'passkey' => 'test-passkey',
                'stk_callback_url' => 'http://localhost:8000/api/v1/payments/stk/callback',
            ],
        ]);
        $org->update(['module_settings' => $settings]);
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
}
