<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\EquityBankAccount;
use App\Models\EquityIncomingPayment;
use App\Models\PlatformLicencePayment;
use App\Models\PlatformPlan;
use App\Models\PlatformSubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
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

    public function test_cancelled_stk_prompt_stays_payable_by_paybill(): void
    {
        [$org, $plan] = $this->seedLicensedOrg('MOON', '0712000111', '2026-09-01');
        $this->configurePaybill();
        $service = app(PlatformPaybillLicenceService::class);
        $checkout = $service->startCheckout($org, $plan, 1, '254712000111', 'monthly');
        $checkout->checkout_request_id = 'ws_CO_licence_cancel';
        $checkout->save();

        $service->acceptStkResult('ws_CO_licence_cancel', 1032, [], 'Request cancelled by user');
        $checkout->refresh();
        $this->assertSame('pending', $checkout->status);
        $this->assertSame(1032, (int) $checkout->result_code);

        $payment = $service->acceptC2b($this->payload('PAYBILL1', 1500, 'MOON'));
        $this->assertSame('applied', $payment->status);
        $checkout->refresh();
        $this->assertSame('paid', $checkout->status);
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

    public function test_public_plan_catalogue_returns_admin_configured_cta_action(): void
    {
        [, $plan] = $this->seedLicensedOrg('MOON', '0712000111', '2026-09-01');
        $plan->update(['self_serve' => true]);
        $admin = User::query()->where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/platform-plans/'.$plan->id, [
            'cta_action' => 'start_now',
        ])->assertOk()->assertJsonPath('data.cta_action', 'start_now');

        $this->getJson('/api/v1/public/self-serve-plans')
            ->assertOk()
            ->assertJsonPath('data.0.cta_action', 'start_now');
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
