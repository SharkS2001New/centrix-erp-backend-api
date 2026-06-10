<?php

namespace Tests\Feature;

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
    }

    public function test_stk_push_rejects_localhost_callback_url(): void
    {
        config([
            'mpesa.consumer_key' => 'test-key',
            'mpesa.consumer_secret' => 'test-secret',
            'mpesa.shortcode' => '5000072',
            'mpesa.till_number' => '8881950',
            'mpesa.passkey' => 'test-passkey',
            'mpesa.callback_url' => 'http://localhost:8000/api/v1/payments/stk/callback',
            'mpesa.env' => 'live',
        ]);

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
                'message' => 'M-Pesa cannot send STK push while MPESA_CALLBACK_URL points to localhost. Use a public HTTPS URL (for example an ngrok tunnel to /api/v1/payments/stk/callback).',
            ]);
    }
}
