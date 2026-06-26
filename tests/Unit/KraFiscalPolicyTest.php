<?php

namespace Tests\Unit;

use App\Services\Kra\KraFiscalPolicy;
use PHPUnit\Framework\TestCase;

class KraFiscalPolicyTest extends TestCase
{
    public function test_should_not_fiscalize_when_device_not_configured(): void
    {
        $this->assertFalse(KraFiscalPolicy::shouldFiscalizeSale(['enable_kra_device' => false], 1000));
    }

    public function test_should_not_fiscalize_when_master_switch_off(): void
    {
        $finance = [
            'enable_kra_device' => true,
            'default_submit_kra' => false,
        ];

        $this->assertFalse(KraFiscalPolicy::shouldFiscalizeSale($finance, 1000, true));
    }

    public function test_should_bypass_when_order_total_meets_threshold(): void
    {
        $finance = [
            'enable_kra_device' => true,
            'default_submit_kra' => true,
            'kra_bypass_above_amount' => 50000,
        ];

        $this->assertFalse(KraFiscalPolicy::shouldFiscalizeSale($finance, 50000));
        $this->assertTrue(KraFiscalPolicy::shouldFiscalizeSale($finance, 49999.99));
    }
}
