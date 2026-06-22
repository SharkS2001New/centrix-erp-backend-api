<?php

namespace Tests\Feature;

use App\Models\KraResponse;
use App\Models\MpesaIncomingPayment;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class TenantIsolationSecurityTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_c2b_confirmation_without_resolvable_shortcode_does_not_store_payment(): void
    {
        $this->postJson('/api/v1/payments/c2b/confirmation', [
            'TransID' => 'UNRESOLVED123',
            'TransAmount' => '100',
            'MSISDN' => '254712345678',
            'BusinessShortCode' => '9999999',
        ])->assertOk();

        $this->assertNull(MpesaIncomingPayment::query()->where('transaction_id', 'UNRESOLVED123')->first());
    }

    public function test_c2b_confirmation_maps_payment_to_matching_organization(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'mpesa' => array_merge(config('erp.module_settings_defaults.finance.mpesa', []), [
                'child_storecode' => '6563610',
            ]),
        ]);
        $org->update(['module_settings' => $settings]);

        $this->postJson('/api/v1/payments/c2b/confirmation', [
            'TransID' => 'ORGMAP123',
            'TransAmount' => '150',
            'MSISDN' => '254712345678',
            'BusinessShortCode' => '6563610',
        ])->assertOk();

        $payment = MpesaIncomingPayment::query()->where('transaction_id', 'ORGMAP123')->first();
        $this->assertNotNull($payment);
        $this->assertSame((int) $org->id, (int) $payment->organization_id);
    }

    public function test_kra_responses_list_is_scoped_to_authenticated_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();

        $otherOrg = Organization::query()->where('id', '!=', $org->id)->first();
        if (! $otherOrg) {
            $this->markTestSkipped('Need a second organization in seed data.');
        }

        $foreignSale = Sale::query()->where('organization_id', $otherOrg->id)->first();
        if (! $foreignSale) {
            $this->markTestSkipped('Need a sale in a second organization.');
        }

        $foreignResponse = KraResponse::create([
            'sale_id' => $foreignSale->id,
            'order_no' => 'FOREIGN-ORDER',
            'status' => 'failed',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/kra-responses/'.$foreignResponse->id)->assertNotFound();
    }
}
