<?php

namespace Tests\Feature;

use App\Models\MpesaPaybillAccount;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MpesaC2bUrlRegistrationTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_org_admin_can_register_default_c2b_urls_with_daraja(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);
        $this->saveDefaultMpesaSettings($admin, [
            'consumer_key' => 'default-key',
            'consumer_secret' => 'default-secret',
            'child_storecode' => '600001',
            'c2b_confirmation_url' => 'https://api.centrixerp.com/api/v1/payments/c2b/confirmation',
            'c2b_validation_url' => 'https://api.centrixerp.com/api/v1/payments/c2b/validation',
        ]);
        $this->fakeDarajaRegistration();

        $this->postJson('/api/v1/mpesa-paybill-accounts/register-default-c2b-urls')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('registration.response_code', '0');

        Http::assertSent(fn (ClientRequest $request) => str_ends_with(
            $request->url(),
            '/mpesa/c2b/v1/registerurl',
        ) && $request['ShortCode'] === '600001'
            && $request['ResponseType'] === 'Completed'
            && $request['ConfirmationURL'] === 'https://api.centrixerp.com/api/v1/payments/c2b/confirmation'
            && $request['ValidationURL'] === 'https://api.centrixerp.com/api/v1/payments/c2b/validation'
            && $request->hasHeader('Authorization', 'Bearer test-access-token'));
    }

    public function test_account_registration_uses_its_saved_credentials_and_callback_urls(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);
        $this->saveDefaultMpesaSettings($admin, [
            'consumer_key' => 'org-key',
            'consumer_secret' => 'org-secret',
            'child_storecode' => '600001',
            'c2b_confirmation_url' => 'https://org.centrixerp.com/api/v1/payments/c2b/confirmation',
            'c2b_validation_url' => 'https://org.centrixerp.com/api/v1/payments/c2b/validation',
        ]);
        $account = MpesaPaybillAccount::query()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'Shop paybill',
            'primary_short_code' => '700002',
            'child_storecode' => '700002',
            'is_active' => true,
            'is_default' => false,
            'env' => 'sandbox',
            'consumer_key' => 'account-key',
            'consumer_secret' => 'account-secret',
            'c2b_confirmation_url' => 'https://shop.centrixerp.com/api/v1/payments/c2b/confirmation',
            'c2b_validation_url' => 'https://shop.centrixerp.com/api/v1/payments/c2b/validation',
        ]);
        $this->fakeDarajaRegistration();

        $this->postJson("/api/v1/mpesa-paybill-accounts/{$account->id}/register-c2b-urls")
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn (ClientRequest $request) => str_ends_with(
            $request->url(),
            '/oauth/v1/generate?grant_type=client_credentials',
        ) && $request->hasHeader('Authorization', 'Basic '.base64_encode('account-key:account-secret')));
        Http::assertSent(fn (ClientRequest $request) => str_ends_with(
            $request->url(),
            '/mpesa/c2b/v1/registerurl',
        ) && $request['ShortCode'] === '700002'
            && $request['ConfirmationURL'] === 'https://shop.centrixerp.com/api/v1/payments/c2b/confirmation'
            && $request['ValidationURL'] === 'https://shop.centrixerp.com/api/v1/payments/c2b/validation');
    }

    public function test_c2b_registration_reports_incomplete_configuration_without_calling_daraja(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/mpesa-paybill-accounts/register-default-c2b-urls')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mpesa');

        Http::assertNothingSent();
    }

    public function test_stk_callback_is_not_used_as_a_c2b_confirmation_url(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);
        $this->saveDefaultMpesaSettings($admin, [
            'consumer_key' => 'default-key',
            'consumer_secret' => 'default-secret',
            'child_storecode' => '600001',
            'c2b_confirmation_url' => '',
            'stk_callback_url' => 'https://api.centrixerp.com/api/v1/payments/stk/callback',
            'c2b_validation_url' => 'https://api.centrixerp.com/api/v1/payments/c2b/validation',
        ]);
        Http::fake();

        $this->postJson('/api/v1/mpesa-paybill-accounts/register-default-c2b-urls')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mpesa');

        Http::assertNothingSent();
    }

    public function test_account_registration_cannot_access_another_organizations_account(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $otherOrganization = Organization::query()
            ->where('id', '!=', $admin->organization_id)
            ->firstOrFail();
        Sanctum::actingAs($admin);
        $account = MpesaPaybillAccount::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other organization paybill',
            'primary_short_code' => '800003',
            'child_storecode' => '800003',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->postJson("/api/v1/mpesa-paybill-accounts/{$account->id}/register-c2b-urls")
            ->assertNotFound();

        Http::assertNothingSent();
    }

    /** @param  array<string, mixed>  $mpesa */
    private function saveDefaultMpesaSettings(User $user, array $mpesa): void
    {
        $organization = Organization::query()->findOrFail($user->organization_id);
        $settings = $organization->module_settings ?? [];
        $settings['finance']['mpesa'] = array_merge(
            $settings['finance']['mpesa'] ?? [],
            $mpesa,
        );
        $organization->update(['module_settings' => $settings]);
    }

    private function fakeDarajaRegistration(): void
    {
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => '3599',
            ]),
            'sandbox.safaricom.co.ke/mpesa/c2b/v1/registerurl' => Http::response([
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing.',
                'OriginatorCoversationID' => 'test-originator-id',
                'ConversationID' => 'test-conversation-id',
            ]),
        ]);
    }
}
