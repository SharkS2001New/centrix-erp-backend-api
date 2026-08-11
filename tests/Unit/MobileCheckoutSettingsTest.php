<?php

namespace Tests\Unit;

use App\Services\Sales\MobileCheckoutSettings;
use PHPUnit\Framework\TestCase;

class MobileCheckoutSettingsTest extends TestCase
{
    public function test_save_only_mode_clears_payment_method_so_receipts_do_not_invent_cash(): void
    {
        $service = new MobileCheckoutSettings;
        $input = [
            'pay_now' => 500,
            'payment_method_code' => 'CASH',
            'is_credit_sale' => true,
        ];

        $service->applyCheckoutPolicy(
            ['mobile_checkout_mode' => MobileCheckoutSettings::MODE_SAVE_ONLY],
            $input,
            'mobile',
        );

        $this->assertTrue($input['save_only']);
        $this->assertSame(0, $input['pay_now']);
        $this->assertFalse($input['is_credit_sale']);
        $this->assertArrayNotHasKey('payment_method_code', $input);
    }

    public function test_non_mobile_channel_is_left_unchanged(): void
    {
        $service = new MobileCheckoutSettings;
        $input = [
            'pay_now' => 500,
            'payment_method_code' => 'CASH',
        ];

        $service->applyCheckoutPolicy(
            ['mobile_checkout_mode' => MobileCheckoutSettings::MODE_SAVE_ONLY],
            $input,
            'pos',
        );

        $this->assertSame(500, $input['pay_now']);
        $this->assertSame('CASH', $input['payment_method_code']);
        $this->assertArrayNotHasKey('save_only', $input);
    }
}
