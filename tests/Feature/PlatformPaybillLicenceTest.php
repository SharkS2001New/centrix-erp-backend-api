<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\EquityBankAccount;
use App\Models\EquityIncomingPayment;
use App\Models\InAppNotification;
use App\Models\PlatformLicencePayment;
use App\Models\PlatformContract;
use App\Models\PlatformPlan;
use App\Models\PlatformSubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use App\Services\Platform\PlatformPaybillLicenceService;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformPaybillLicenceTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_org_code_payment_extends_monthly_and_replay_does_not_double(): void
    {
        [$org, $plan] = $this->seedLicensedOrg('MOON', '0712000111', '2026-09-01');
        $this->configurePaybill();

        $service = app(PlatformPaybillLicenceService::class);
        $first = $service->acceptC2b($this->payload('TX1', 1500, 'MOON'));

        $this->assertSame('applied', $first->status);
        $this->assertSame('monthly', $first->product);
        $this->assertSame('org_code', $first->match_method);
        $org->refresh();
        $sub = PlatformSubscription::query()->where('organization_id', $org->id)->first();
        $this->assertSame('active', $sub->status);
        $this->assertFalse((bool) $sub->is_trial);
        $this->assertNull($sub->trial_ends_at);
        $this->assertSame('2026-11-07', $sub->current_period_end->toDateString());

        $second = $service->acceptC2b($this->payload('TX1', 1500, 'MOON'));
        $this->assertSame($first->id, $second->id);
        $sub->refresh();
        $this->assertSame('2026-11-07', $sub->current_period_end->toDateString());
        $this->assertSame(1, PlatformLicencePayment::query()->count());
        $this->assertSame($plan->id, $sub->plan_id);
    }

    public function test_monthly_subscription_can_start_a_renewal_checkout(): void
    {
        [$org] = $this->seedLicensedOrg('MONTHLY', '0712000112', '2026-11-07');
        $subscription = PlatformSubscription::query()
            ->where('organization_id', $org->id)
            ->firstOrFail();
        $subscription->update([
            'status' => 'active',
            'is_trial' => false,
            'renewal_price' => 1500,
            'amount' => 1500,
            'billing_cycle' => 'monthly',
        ]);

        $checkout = app(PlatformPaybillLicenceService::class)->startRenewalCheckout($org);

        $this->assertSame(1, (int) $checkout->months);
        $this->assertSame(1500.0, (float) $checkout->amount);
        $this->assertSame($subscription->plan_id, $checkout->plan_id);
    }

    public function test_expired_trial_remains_identified_as_a_trial_in_the_license_payload(): void
    {
        [$org] = $this->seedLicensedOrg('TRIAL', '0712000115', '2026-09-01');

        $license = app(\App\Services\Platform\OrganizationLicenseService::class)
            ->resolveForOrganization($org);

        $this->assertSame('expired', $license['status']);
        $this->assertTrue($license['is_trial']);
    }

    public function test_annual_contract_without_a_plan_can_renew_and_activate_after_payment(): void
    {
        [$org] = $this->seedLicensedOrg('CONTRACT', '0712000116', '2026-09-01');
        $contract = PlatformContract::query()->create([
            'kind' => 'contract',
            'status' => 'active',
            'organization_id' => $org->id,
            'title' => 'Annual Enterprise Contract',
            'interval' => 'annual',
            'amount' => 30000,
            'renewal_price' => 24000,
            'currency' => 'KES',
        ]);
        $subscription = PlatformSubscription::query()
            ->where('organization_id', $org->id)
            ->firstOrFail();
        $subscription->update([
            'plan_id' => null,
            'contract_id' => $contract->id,
            'status' => 'expired',
            'is_trial' => false,
            'current_period_start' => '2025-10-08',
            'current_period_end' => '2026-10-07',
            'amount' => 30000,
            'renewal_price' => 24000,
            'billing_cycle' => 'monthly',
        ]);
        $this->configurePaybill();

        $licenses = app(\App\Services\Platform\OrganizationLicenseService::class);
        $license = $licenses->resolveForOrganization($org);
        $this->assertSame('yearly', $license['billing_cycle']);
        $this->assertSame(24000.0, $license['renewal_amount']);

        $service = app(PlatformPaybillLicenceService::class);
        $checkout = $service->startRenewalCheckout($org);
        $this->assertNull($checkout->plan_id);
        $this->assertSame(12, (int) $checkout->months);
        $this->assertSame(24000.0, (float) $checkout->amount);

        $payment = $service->acceptC2b($this->payload('CONTRACT-RENEWAL-1', 24000, 'CONTRACT'));

        $this->assertSame('applied', $payment->status);
        $this->assertSame('yearly', $payment->product);
        $this->assertSame('contract_renewal', $payment->note);
        $this->assertSame('paid', $checkout->fresh()->status);
        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertFalse((bool) $subscription->is_trial);
        $this->assertSame('2027-10-08', $subscription->current_period_end->toDateString());
    }

    public function test_manual_and_equity_checkout_support_paid_subscription_renewals(): void
    {
        [$org] = $this->seedLicensedOrg('RENEW', '0712000114', '2026-11-07');
        $subscription = PlatformSubscription::query()
            ->where('organization_id', $org->id)
            ->firstOrFail();
        $subscription->update([
            'status' => 'active',
            'is_trial' => false,
            'renewal_price' => 1500,
            'amount' => 1500,
            'billing_cycle' => 'monthly',
        ]);
        $this->configurePaybill();
        $user = $this->userForOrg($org);
        Sanctum::actingAs($user);

        $manual = $this->postJson('/api/v1/erp/organization/licence/checkout', [
            'billing' => 'renew',
            'months' => 1,
        ])->assertCreated()
            ->assertJsonPath('checkout.amount', 1500)
            ->assertJsonPath('checkout.months', 1);
        $this->assertNotEmpty($manual->json('paybill.short_code'));

        $platform = Organization::query()
            ->whereRaw('UPPER(company_code) = ?', ['PLATFORM'])
            ->firstOrFail();
        EquityBankAccount::query()->where('organization_id', $platform->id)->update(['is_active' => false]);
        EquityBankAccount::query()->create([
            'organization_id' => $platform->id,
            'name' => 'Platform Equity',
            'primary_account_number' => 'EQRENEW1',
            'account_number' => 'EQRENEW1',
            'paybill_number' => 'EQRENEW1',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/erp/organization/licence/equity/checkout', [
            'billing' => 'renew',
            'months' => 1,
        ])->assertCreated()
            ->assertJsonPath('checkout.amount', 1500)
            ->assertJsonPath('checkout.months', 1)
            ->assertJsonPath('equity.account_number', 'EQRENEW1');
    }

    public function test_billing_details_include_the_assigned_platform_managed_plan(): void
    {
        [$org, $plan] = $this->seedLicensedOrg('MANAGED', '0712000113', '2026-11-07');
        $plan->update([
            'self_serve' => false,
            'description' => 'Configured for this organization.',
            'feature_details' => [['label' => 'Inventory', 'detail' => 'Multi-branch stock']],
        ]);
        PlatformSubscription::query()
            ->where('organization_id', $org->id)
            ->update([
                'status' => 'active',
                'is_trial' => false,
                'renewal_price' => 1500,
                'amount' => 1500,
            ]);

        Sanctum::actingAs($this->userForOrg($org));

        $this->getJson('/api/v1/erp/organization/billing')
            ->assertOk()
            ->assertJsonPath('data.subscription.plan.name', 'MANAGED plan')
            ->assertJsonPath('data.subscription.plan.self_serve', false)
            ->assertJsonPath('data.subscription.plan.description', 'Configured for this organization.')
            ->assertJsonPath('data.subscription.plan.features.0', 'Inventory: Multi-branch stock')
            ->assertJsonPath('data.subscription.billing_cycle', 'monthly');
    }

    public function test_organization_billing_exposes_manual_payment_when_paybill_is_configured(): void
    {
        [$org] = $this->seedLicensedOrg('PAYMENTS', '0712000114', '2026-11-07');
        $this->configurePaybill();
        Sanctum::actingAs($this->userForOrg($org));

        $this->getJson('/api/v1/erp/organization/billing')
            ->assertOk()
            ->assertJsonPath('data.payment_methods.mpesa_manual', true)
            ->assertJsonPath('data.payment_methods.mpesa_stk', false)
            ->assertJsonPath('data.payment_methods.equity', false)
            ->assertJsonPath('data.paybill.short_code', '4036507');
    }

    public function test_amount_that_is_not_a_time_plan_becomes_tokens(): void
    {
        [$org] = $this->seedLicensedOrg('OMEGA', '0712333444', '2026-12-01');
        $this->configurePaybill();
        $service = app(PlatformPaybillLicenceService::class);

        $partial = $service->acceptC2b($this->payload('TXP1', 400, '254712333444'));
        $this->assertSame('applied', $partial->status);
        $this->assertSame('token', $partial->product);
        $this->assertSame('admin_phone', $partial->match_method);
        $this->assertSame(400, (int) $partial->amount_applied);

        $rest = $service->acceptC2b($this->payload('TXP2', 1100, '0712333444'));
        $this->assertSame('applied', $rest->status);
        $this->assertSame('token', $rest->product);
        $sub = PlatformSubscription::query()->where('organization_id', $org->id)->first();
        $this->assertSame('2026-12-01', $sub->current_period_end->toDateString());
        $this->assertSame(1500, app(\App\Services\Platform\OrganizationLicenseService::class)->tokenBalanceFor($org));
    }

    public function test_yearly_amount_and_account_prefix(): void
    {
        [$org] = $this->seedLicensedOrg('MOON', '0799000111', '2026-01-01');
        $this->configurePaybill('CENTRIX');
        $payment = app(PlatformPaybillLicenceService::class)->acceptC2b(
            $this->payload('TXY', 12000, 'CENTRIX MOON'),
        );

        $this->assertSame('applied', $payment->status);
        $this->assertSame('yearly', $payment->product);
        $this->assertSame('MOON', $payment->account_reference);
        $sub = PlatformSubscription::query()->where('organization_id', $org->id)->first();
        $this->assertSame('2027-10-08', $sub->current_period_end->toDateString());
    }

    public function test_token_pack_and_unknown_account(): void
    {
        [$org] = $this->seedLicensedOrg('MOON', '0712000001', '2026-10-20');
        $this->configurePaybill();
        $service = app(PlatformPaybillLicenceService::class);

        $token = $service->acceptC2b($this->payload('TXT', 500, 'moon'));
        $this->assertSame('applied', $token->status);
        $this->assertSame('token', $token->product);
        $this->assertSame(500, (int) $token->amount_applied);
        $sub = PlatformSubscription::query()->where('organization_id', $org->id)->first();
        $this->assertSame('2026-10-20', $sub->current_period_end->toDateString());
        $licenses = app(\App\Services\Platform\OrganizationLicenseService::class);
        $this->assertSame(500, $licenses->tokenBalanceFor($org));
        $this->assertTrue($licenses->reserveToken($org->id));
        $this->assertSame(499, $licenses->tokenBalanceFor($org));
        $licenses->refundToken($org->id);
        $this->assertSame(500, $licenses->tokenBalanceFor($org));

        $unknown = $service->acceptC2b($this->payload('TXU', 1500, 'NOBODY'));
        $this->assertSame('unmatched', $unknown->status);
        $this->assertNull($unknown->organization_id);
        $this->assertSame('unknown_account', $unknown->note);
    }

    public function test_shop_paybill_is_not_a_licence_payment(): void
    {
        $this->configurePaybill();
        $this->postJson('/api/v1/payments/c2b/confirmation', [
            'TransID' => 'SHOP1',
            'TransAmount' => 1500,
            'MSISDN' => '254700000000',
            'BillRefNumber' => 'MOON',
            'BusinessShortCode' => '999111',
        ])->assertOk();

        $this->assertSame(0, PlatformLicencePayment::query()->count());
    }

    public function test_stk_callback_applies_the_subscription_checkout(): void
    {
        [$org, $plan] = $this->seedLicensedOrg('MOON', '0712000111', '2026-09-01');
        $this->configurePaybill();
        $service = app(PlatformPaybillLicenceService::class);
        $checkout = $service->startCheckout($org, $plan, 1, '254712000111', 'monthly');
        $checkout->checkout_request_id = 'ws_CO_licence_1';
        $checkout->save();

        $this->postJson('/api/v1/payments/stk/callback', [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'ws_CO_licence_1',
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 1500],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'STKMOON1'],
                            ['Name' => 'PhoneNumber', 'Value' => '254712000111'],
                        ],
                    ],
                ],
            ],
        ])->assertOk();

        $checkout->refresh();
        $this->assertSame('paid', $checkout->status);
        $this->assertSame('STKMOON1', $checkout->mpesa_receipt);
        $sub = PlatformSubscription::query()->where('organization_id', $org->id)->first();
        $this->assertSame('active', $sub->status);
        $this->assertSame('2026-11-07', $sub->current_period_end->toDateString());
    }

    public function test_stk_query_fallback_applies_a_success_when_callback_is_delayed(): void
    {
        [$org, $plan] = $this->seedLicensedOrg('STKQUERY', '0712000117', '2026-09-01');
        $service = app(PlatformPaybillLicenceService::class);
        $service->saveSettings([
            'short_code' => '4036507',
            'stk_env' => 'sandbox',
            'stk_shortcode' => '4036507',
            'stk_consumer_key' => 'platform-consumer-key',
            'stk_consumer_secret' => 'platform-consumer-secret',
            'stk_passkey' => 'platform-passkey',
            'stk_callback_url' => 'https://api.centrixerp.com/api/v1/payments/stk/callback',
        ]);
        $checkout = $service->startCheckout($org, $plan, 1, '254712000117', 'monthly');
        $checkout->update(['checkout_request_id' => 'ws_CO_stk_query_success']);

        Http::fake([
            'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'platform-query-token',
            ]),
            'sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'ResultCode' => '0',
                'ResultDesc' => 'The service request is processed successfully.',
            ]),
        ]);
        Sanctum::actingAs($this->userForOrg($org));

        $this->getJson('/api/v1/erp/organization/licence/status?checkout_id='.$checkout->id)
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('stk_failed', false)
            ->assertJsonPath('license.status', 'active');

        $this->assertSame('paid', $checkout->fresh()->status);
        $this->assertSame('stk_query_confirmed', PlatformLicencePayment::query()->value('note'));
        Http::assertSent(fn (ClientRequest $request) => str_ends_with(
            $request->url(),
            '/mpesa/stkpushquery/v1/query',
        ) && $request['CheckoutRequestID'] === 'ws_CO_stk_query_success'
            && $request['BusinessShortCode'] === '4036507');

        $this->postJson('/api/v1/payments/stk/callback', [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'ws_CO_stk_query_success',
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 1500],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'STKQUERY1'],
                            ['Name' => 'PhoneNumber', 'Value' => '254712000117'],
                        ],
                    ],
                ],
            ],
        ])->assertOk();
        $this->assertSame('STKQUERY1', $checkout->fresh()->mpesa_receipt);
        $this->assertDatabaseHas('platform_licence_payments', [
            'mpesa_transaction_id' => 'STKQUERY1',
            'note' => 'stk_query_confirmed',
        ]);
    }

    public function test_cancelled_stk_prompt_stays_payable_by_paybill(): void
    {
        [$org, $plan] = $this->seedLicensedOrg('MOON', '0712000111', '2026-09-01');
        $this->configurePaybill();
        $service = app(PlatformPaybillLicenceService::class);
        $checkout = $service->startCheckout($org, $plan, 1, '254712000111', 'monthly');
        $checkout->checkout_request_id = 'ws_CO_licence_cancel';
        $checkout->save();

        $this->postJson('/api/v1/payments/stk/callback', [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'ws_CO_licence_cancel',
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user',
                ],
            ],
        ])->assertOk();
        $checkout->refresh();
        $this->assertSame('pending', $checkout->status);
        $this->assertSame(1032, (int) $checkout->result_code);

        Sanctum::actingAs($this->userForOrg($org));
        $this->getJson('/api/v1/erp/organization/licence/status?checkout_id='.$checkout->id)
            ->assertOk()
            ->assertJsonPath('stk_failed', true)
            ->assertJsonPath('message', 'Payment cancelled on the phone. You can send the prompt again, or pay the Paybill.');

        $payment = $service->acceptC2b($this->payload('PAYBILL1', 1500, 'MOON'));
        $this->assertSame('applied', $payment->status);
        $checkout->refresh();
        $this->assertSame('paid', $checkout->status);

        $insufficientCheckout = $service->startCheckout($org, $plan, 1, '254712000111', 'monthly');
        $insufficientCheckout->update(['checkout_request_id' => 'ws_CO_licence_insufficient']);
        $this->postJson('/api/v1/payments/stk/callback', [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'ws_CO_licence_insufficient',
                    'ResultCode' => 1,
                    'ResultDesc' => 'The balance is insufficient for the transaction.',
                ],
            ],
        ])->assertOk();
        $this->getJson('/api/v1/erp/organization/licence/status?checkout_id='.$insufficientCheckout->id)
            ->assertOk()
            ->assertJsonPath('stk_failed', true)
            ->assertJsonPath('message', 'Insufficient M-Pesa balance.');
    }

    public function test_centrix_callback_and_admin_list(): void
    {
        [$org] = $this->seedLicensedOrg('MOON', '0712000111', '2026-09-01');
        $admin = User::query()->where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/admin/platform-paybill', [
            'short_code' => '4036507',
            'account_name_prefix' => '',
        ])->assertOk()->assertJsonPath('data.short_code', '4036507');

        $this->postJson('/api/v1/payments/c2b/confirmation', $this->payload('TXHTTP', 1500, 'MOON'))
            ->assertOk()
            ->assertJsonPath('ResultCode', 0);

        $this->getJson('/api/v1/admin/platform-licence-payments?status=applied')
            ->assertOk()
            ->assertJsonPath('data.0.organization.company_code', $org->company_code)
            ->assertJsonPath('data.0.product', 'monthly');
    }

    public function test_admin_payment_settings_keep_stk_secrets_private_and_allow_clear(): void
    {
        [$org] = $this->seedLicensedOrg('MOON', '0712000111', '2026-09-01');
        Sanctum::actingAs(User::query()->where('username', 'superadmin')->firstOrFail());

        $this->putJson('/api/v1/admin/platform-paybill', [
            'short_code' => '4036507',
            'manual_mpesa_account_name' => 'CentrixERP',
            'manual_mpesa_instructions' => 'Use the organisation code as the account reference.',
            'stk_env' => 'sandbox',
            'stk_consumer_key' => 'consumer-key-value',
            'stk_consumer_secret' => 'consumer-secret-value',
            'stk_shortcode' => '174379',
            'stk_passkey' => 'passkey-value',
            'stk_callback_url' => 'https://api.example.test/api/v1/payments/stk/callback',
        ])->assertOk()
            ->assertJsonPath('data.has_stk_consumer_key', true)
            ->assertJsonPath('data.has_stk_consumer_secret', true)
            ->assertJsonPath('data.has_stk_passkey', true)
            ->assertJsonPath('data.payment_methods.mpesa_manual', true)
            ->assertJsonPath('data.payment_methods.mpesa_stk', true)
            ->assertJsonMissingPath('data.stk_consumer_secret')
            ->assertJsonPath('data.stk_consumer_key', '********');

        $settings = app(PlatformPaybillLicenceService::class)->settings();
        $this->assertSame('consumer-secret-value', $settings->stk_consumer_secret);
        $this->assertNotSame('consumer-key-value', $settings->getRawOriginal('stk_consumer_key'));
        $this->assertNotSame('consumer-secret-value', $settings->getRawOriginal('stk_consumer_secret'));
        $this->assertTrue(app(PlatformPaybillLicenceService::class)->paymentMethodsFor($org)['mpesa_stk']);

        app(PlatformPaybillLicenceService::class)->saveSettings([
            'stk_consumer_key' => '',
            'stk_consumer_secret' => '',
            'stk_passkey' => '',
        ]);
        $this->assertSame('consumer-key-value', $settings->fresh()->stk_consumer_key);
        $this->assertSame('consumer-secret-value', $settings->fresh()->stk_consumer_secret);

        app(PlatformPaybillLicenceService::class)->saveSettings(['clear_stk_credentials' => true]);
        $this->assertFalse(app(PlatformPaybillLicenceService::class)->stkConfigured());
        $this->assertSame('4036507', app(PlatformPaybillLicenceService::class)->settings()->short_code);
    }

    public function test_plan_catalogue_returns_admin_configured_cta_action_and_label(): void
    {
        [$org, $plan] = $this->seedLicensedOrg('MOON', '0712000111', '2026-09-01');
        $plan->update(['self_serve' => true]);
        $admin = User::query()->where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/platform-plans/'.$plan->id, [
            'cta_action' => 'start_now',
            'cta_label' => 'Subscribe today',
        ])->assertOk()
            ->assertJsonPath('data.cta_action', 'start_now')
            ->assertJsonPath('data.cta_label', 'Subscribe today');

        $this->getJson('/api/v1/public/self-serve-plans')
            ->assertOk()
            ->assertJsonPath('data.0.cta_action', 'start_now')
            ->assertJsonPath('data.0.cta_label', 'Subscribe today');

        $choices = app(PlatformPaybillLicenceService::class)->monthlyPlanChoices($org);
        $this->assertSame(
            'Subscribe today',
            collect($choices)->firstWhere('id', $plan->id)['cta_label'],
        );
    }

    public function test_plan_cta_label_defaults_to_action_specific_payment_copy(): void
    {
        $plan = new PlatformPlan(['cta_action' => 'start_now']);
        $this->assertSame('Subscribe Now', $plan->callToActionLabel());

        $plan->cta_action = 'buy_now';
        $this->assertSame('Pay Now', $plan->callToActionLabel());
    }

    public function test_equity_checkout_applies_verified_callback_and_is_idempotent(): void
    {
        [$org, $plan] = $this->seedLicensedOrg('MOON', '0712000111', '2026-09-01');
        $platform = Organization::query()
            ->whereRaw('UPPER(company_code) = ?', ['PLATFORM'])
            ->firstOrFail();
        EquityBankAccount::query()->where('organization_id', $platform->id)->update(['is_active' => false]);
        $account = EquityBankAccount::query()->create([
            'organization_id' => $platform->id,
            'name' => 'Platform Equity',
            'primary_account_number' => 'EQPLATFORM1',
            'account_number' => 'EQPLATFORM1',
            'paybill_number' => 'EQPLATFORM1',
            'is_default' => true,
            'is_active' => true,
        ]);
        $checkout = app(PlatformPaybillLicenceService::class)->startCheckout($org, $plan, 1);
        $incoming = EquityIncomingPayment::query()->create([
            'organization_id' => $platform->id,
            'equity_bank_account_id' => $account->id,
            'transaction_id' => 'EQPLANPAY001',
            'phone_number' => '254711000111',
            'bill_ref_number' => 'MOON',
            'business_account_number' => 'EQPLATFORM1',
            'amount' => 1500,
            'source' => 'callback',
            'status' => 'available',
            'reconciliation_status' => 'unmatched',
            'received_at' => now(),
        ]);
        $user = $this->userForOrg($org);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/erp/organization/licence/equity/verify', [
            'checkout_id' => $checkout->id,
            'transaction_id' => $incoming->transaction_id,
        ])->assertOk()->assertJsonPath('confirmed', true);

        $this->postJson('/api/v1/erp/organization/licence/equity/verify', [
            'checkout_id' => $checkout->id,
            'transaction_id' => $incoming->transaction_id,
        ])->assertOk()->assertJsonPath('confirmed', true);

        $this->assertSame('paid', $checkout->fresh()->status);
        $this->assertSame('applied', $incoming->fresh()->status);
        $this->assertSame(1, PlatformLicencePayment::query()->count());
        $subscription = PlatformSubscription::query()->where('organization_id', $org->id)->firstOrFail();
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame('active', $subscription->status);
    }

    public function test_equity_checkout_rejects_wrong_amount_and_reference(): void
    {
        [$org, $plan] = $this->seedLicensedOrg('MOON', '0712000111', '2026-09-01');
        $platform = Organization::query()
            ->whereRaw('UPPER(company_code) = ?', ['PLATFORM'])
            ->firstOrFail();
        EquityBankAccount::query()->where('organization_id', $platform->id)->update(['is_active' => false]);
        $account = EquityBankAccount::query()->create([
            'organization_id' => $platform->id,
            'name' => 'Platform Equity',
            'primary_account_number' => 'EQPLATFORM2',
            'account_number' => 'EQPLATFORM2',
            'paybill_number' => 'EQPLATFORM2',
            'is_default' => true,
            'is_active' => true,
        ]);
        $checkout = app(PlatformPaybillLicenceService::class)->startCheckout($org, $plan, 1);
        EquityIncomingPayment::query()->create([
            'organization_id' => $platform->id,
            'equity_bank_account_id' => $account->id,
            'transaction_id' => 'EQPLANPAY002',
            'bill_ref_number' => 'OTHER',
            'business_account_number' => 'EQPLATFORM2',
            'amount' => 1200,
            'source' => 'callback',
            'status' => 'available',
            'reconciliation_status' => 'unmatched',
            'received_at' => now(),
        ]);
        $user = $this->userForOrg($org);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/erp/organization/licence/equity/verify', [
            'checkout_id' => $checkout->id,
            'transaction_id' => 'EQPLANPAY002',
        ])->assertUnprocessable();

        $this->assertSame('pending', $checkout->fresh()->status);
        $this->assertSame('available', EquityIncomingPayment::query()->where('transaction_id', 'EQPLANPAY002')->firstOrFail()->status);
        $this->assertSame(0, PlatformLicencePayment::query()->count());
    }

    public function test_manual_equity_payment_reference_notifies_platform_admin_without_activating(): void
    {
        [$org, $plan] = $this->seedLicensedOrg('MOON', '0712000111', '2026-09-01');
        $platform = Organization::query()
            ->whereRaw('UPPER(company_code) = ?', ['PLATFORM'])
            ->firstOrFail();
        EquityBankAccount::query()->where('organization_id', $platform->id)->update(['is_active' => false]);

        $service = app(PlatformPaybillLicenceService::class);
        $service->saveSettings([
            'equity_manual_paybill' => '123456',
            'equity_manual_account_name' => 'Centrix Equity',
            'equity_manual_account_number' => 'PLATFORM-EQ',
            'equity_manual_instructions' => 'Use the organisation code as the reference.',
        ]);
        $this->assertSame('123456', $service->equityInstructionsFor($org)['paybill_number']);
        $this->assertTrue($service->paymentMethodsFor($org)['equity']);

        $checkout = $service->startCheckout($org, $plan, 1, null, 'monthly');
        $admin = User::query()->create([
            'organization_id' => $platform->id,
            'role_id' => Role::query()->where('role_name', 'Administrator')->where('scope', 'org')->value('id'),
            'username' => 'platform_admin_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Platform Admin',
            'is_admin' => true,
            'is_super_admin' => true,
            'access_scope' => 'org',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->userForOrg($org));

        $payload = [
            'checkout_id' => $checkout->id,
            'transaction_id' => 'EQMANUAL123',
        ];
        $this->postJson('/api/v1/erp/organization/licence/equity/notify', $payload)
            ->assertOk()
            ->assertJsonPath('admin_notified', true);
        $this->postJson('/api/v1/erp/organization/licence/equity/notify', $payload)
            ->assertOk()
            ->assertJsonPath('admin_notified', true);

        $this->assertSame('pending', $checkout->fresh()->status);
        $this->assertSame('EQMANUAL123', $checkout->fresh()->manual_payment_reference);
        $this->assertNotNull($checkout->fresh()->manual_payment_notified_at);
        $this->assertSame(1, InAppNotification::query()->where('user_id', $admin->id)->count());
        $this->assertSame('trialing', PlatformSubscription::query()->where('organization_id', $org->id)->firstOrFail()->status);
    }

    protected function userForOrg(Organization $org): User
    {
        return User::query()->create([
            'organization_id' => $org->id,
            'role_id' => Role::query()->where('role_name', 'Administrator')->where('scope', 'org')->value('id'),
            'username' => 'equity_user_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Equity Test User',
            'is_admin' => true,
            'access_scope' => 'org',
            'is_active' => true,
        ]);
    }

    /** @return array{0: Organization, 1: PlatformPlan} */
    protected function seedLicensedOrg(string $code, string $phone, string $periodEnd): array
    {
        $org = Organization::query()->create([
            'company_code' => $code,
            'org_name' => $code.' Shop',
            'org_email' => strtolower($code).'@example.com',
            'primary_tel' => $phone,
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
            'is_active' => true,
        ]);

        $plan = PlatformPlan::query()->create([
            'name' => $code.' plan',
            'code' => $code.'-PLAN',
            'interval' => 'monthly',
            'license_basis' => 'org',
            'price' => 1500,
            'renewal_price' => 1500,
            'monthly_price' => 1500,
            'yearly_price' => 12000,
            'token_pack_price' => 500,
            'token_pack_days' => 20,
            'currency' => 'KES',
            'is_active' => true,
        ]);

        PlatformSubscription::query()->create([
            'organization_id' => $org->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'is_trial' => true,
            'trial_ends_at' => $periodEnd,
            'current_period_start' => '2026-08-01',
            'current_period_end' => $periodEnd,
            'currency' => 'KES',
            'license_basis' => 'org',
        ]);

        return [$org, $plan];
    }

    protected function configurePaybill(?string $prefix = null): void
    {
        app(PlatformPaybillLicenceService::class)->saveSettings([
            'short_code' => '4036507',
            'account_name_prefix' => $prefix,
        ]);
    }

    /** @return array<string, mixed> */
    protected function payload(string $transId, float $amount, string $account): array
    {
        return [
            'TransID' => $transId,
            'TransAmount' => $amount,
            'MSISDN' => '254711000222',
            'BillRefNumber' => $account,
            'BusinessShortCode' => '4036507',
        ];
    }
}
