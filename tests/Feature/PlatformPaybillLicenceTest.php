<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformLicencePayment;
use App\Models\PlatformPlan;
use App\Models\PlatformSubscription;
use App\Models\User;
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

    public function test_admin_phone_partials_stack_into_a_month(): void
    {
        [$org] = $this->seedLicensedOrg('OMEGA', '0712333444', '2026-12-01');
        $this->configurePaybill();
        $service = app(PlatformPaybillLicenceService::class);

        $partial = $service->acceptC2b($this->payload('TXP1', 400, '254712333444'));
        $this->assertSame('unmatched', $partial->status);
        $this->assertSame('awaiting_price', $partial->note);
        $this->assertSame('admin_phone', $partial->match_method);

        $rest = $service->acceptC2b($this->payload('TXP2', 1100, '0712333444'));
        $this->assertSame('applied', $rest->status);
        $this->assertSame('monthly', $rest->product);
        $sub = PlatformSubscription::query()->where('organization_id', $org->id)->first();
        $this->assertSame('2026-12-31', $sub->current_period_end->toDateString());
        $this->assertEquals(0, (float) $rest->balance_after);
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
        $sub = PlatformSubscription::query()->where('organization_id', $org->id)->first();
        $this->assertSame('2026-11-09', $sub->current_period_end->toDateString());

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
