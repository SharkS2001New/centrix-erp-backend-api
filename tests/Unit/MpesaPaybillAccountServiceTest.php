<?php

namespace Tests\Unit;

use App\Models\MpesaPaybillAccount;
use App\Models\Sale;
use App\Services\Mpesa\MpesaPaybillAccountService;
use PHPUnit\Framework\TestCase;

class MpesaPaybillAccountServiceTest extends TestCase
{
    public function test_payment_matches_sale_when_shortcode_equals_expected_account(): void
    {
        $expected = new MpesaPaybillAccount;
        $expected->forceFill([
            'id' => 10,
            'organization_id' => 1,
            'primary_short_code' => '111111',
            'child_storecode' => '111111',
        ]);

        $service = new class($expected) extends MpesaPaybillAccountService
        {
            public function __construct(private MpesaPaybillAccount $expected) {}

            public function expectedAccountForSale(Sale $sale): ?MpesaPaybillAccount
            {
                return $this->expected;
            }
        };

        $sale = new Sale;
        $sale->forceFill(['organization_id' => 1, 'route_id' => 5, 'branch_id' => 2]);

        $paymentAccount = new MpesaPaybillAccount;
        $paymentAccount->forceFill([
            'id' => 10,
            'organization_id' => 1,
            'primary_short_code' => '111111',
        ]);
        $this->assertTrue($service->paymentMatchesSale($paymentAccount, '111111', $sale));

        $other = new MpesaPaybillAccount;
        $other->forceFill([
            'id' => 99,
            'organization_id' => 1,
            'primary_short_code' => '222222',
            'route_id' => null,
            'branch_id' => null,
        ]);
        $this->assertFalse($service->paymentMatchesSale($other, '222222', $sale));
    }

    public function test_legacy_org_wide_match_when_sale_has_no_configured_paybill(): void
    {
        $service = new class extends MpesaPaybillAccountService
        {
            public function expectedAccountForSale(Sale $sale): ?MpesaPaybillAccount
            {
                return null;
            }
        };

        $sale = new Sale;
        $sale->forceFill(['organization_id' => 1]);

        $paymentAccount = new MpesaPaybillAccount;
        $paymentAccount->forceFill([
            'id' => 1,
            'organization_id' => 1,
            'primary_short_code' => '111111',
        ]);
        $this->assertTrue($service->paymentMatchesSale($paymentAccount, '111111', $sale));

        $otherOrg = new MpesaPaybillAccount;
        $otherOrg->forceFill([
            'id' => 2,
            'organization_id' => 99,
            'primary_short_code' => '111111',
        ]);
        $this->assertFalse($service->paymentMatchesSale($otherOrg, '111111', $sale));
    }
}
