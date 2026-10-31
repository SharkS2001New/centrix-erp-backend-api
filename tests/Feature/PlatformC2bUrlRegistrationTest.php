<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformC2bUrlRegistrationTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_platform_admin_can_register_platform_paybill_c2b_urls(): void
    {
        Sanctum::actingAs(User::query()->where('username', 'superadmin')->firstOrFail());

        $this->putJson('/api/v1/admin/platform-paybill', [
            'short_code' => '4036507',
            'stk_env' => 'sandbox',
            'stk_consumer_key' => 'platform-consumer-key',
            'stk_consumer_secret' => 'platform-consumer-secret',
            'c2b_confirmation_url' => 'https://api.centrixerp.com/api/v1/payments/c2b/confirmation',
            'c2b_validation_url' => 'https://api.centrixerp.com/api/v1/payments/c2b/validation',
        ])->assertOk()
            ->assertJsonPath('data.c2b_confirmation_url', 'https://api.centrixerp.com/api/v1/payments/c2b/confirmation')
            ->assertJsonPath('data.c2b_validation_url', 'https://api.centrixerp.com/api/v1/payments/c2b/validation');

        Http::fake([
            'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'platform-test-token',
                'expires_in' => '3599',
            ]),
            'sandbox.safaricom.co.ke/mpesa/c2b/v1/registerurl' => Http::response([
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing.',
            ]),
        ]);

        $this->postJson('/api/v1/admin/platform-paybill/register-c2b-urls')
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn (ClientRequest $request) => str_ends_with(
            $request->url(),
            '/oauth/v1/generate?grant_type=client_credentials',
        ) && $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('platform-consumer-key:platform-consumer-secret'),
        ));
        Http::assertSent(fn (ClientRequest $request) => str_ends_with(
            $request->url(),
            '/mpesa/c2b/v1/registerurl',
        ) && $request['ShortCode'] === '4036507'
            && $request['ConfirmationURL'] === 'https://api.centrixerp.com/api/v1/payments/c2b/confirmation'
            && $request['ValidationURL'] === 'https://api.centrixerp.com/api/v1/payments/c2b/validation');
    }

    public function test_platform_registration_requires_c2b_urls_and_does_not_use_stk_callback(): void
    {
        Sanctum::actingAs(User::query()->where('username', 'superadmin')->firstOrFail());
        $this->putJson('/api/v1/admin/platform-paybill', [
            'short_code' => '4036507',
            'stk_env' => 'sandbox',
            'stk_consumer_key' => 'platform-consumer-key',
            'stk_consumer_secret' => 'platform-consumer-secret',
            'stk_callback_url' => 'https://api.centrixerp.com/api/v1/payments/stk/callback',
            'c2b_validation_url' => 'https://api.centrixerp.com/api/v1/payments/c2b/validation',
        ])->assertOk();
        Http::fake();

        $this->postJson('/api/v1/admin/platform-paybill/register-c2b-urls')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mpesa');

        Http::assertNothingSent();
    }
}
