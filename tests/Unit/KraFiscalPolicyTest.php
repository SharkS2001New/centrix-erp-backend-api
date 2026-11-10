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
        $this->assertSame(KraFiscalPolicy::SCOPE_OFF, KraFiscalPolicy::salesScope($finance));
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

    public function test_client_opt_out_ignored_when_use_kra_is_enabled(): void
    {
        $finance = [
            'enable_kra_device' => true,
            'default_submit_kra' => true,
        ];

        $this->assertTrue(KraFiscalPolicy::shouldFiscalizeSale($finance, 1000, false));
        $this->assertTrue(KraFiscalPolicy::shouldFiscalizeSale($finance, 1000, null));
        $this->assertTrue(KraFiscalPolicy::shouldFiscalizeSale($finance, 1000, true));
    }

    public function test_legacy_boolean_maps_to_both_or_off_scope(): void
    {
        $this->assertSame(
            KraFiscalPolicy::SCOPE_BOTH,
            KraFiscalPolicy::salesScope(['default_submit_kra' => true]),
        );
        $this->assertSame(
            KraFiscalPolicy::SCOPE_OFF,
            KraFiscalPolicy::salesScope(['default_submit_kra' => false]),
        );
    }

    public function test_mobile_scope_only_fiscalizes_mobile_channel(): void
    {
        $finance = [
            'enable_kra_device' => true,
            'kra_sales_scope' => 'mobile',
        ];

        $this->assertTrue(KraFiscalPolicy::shouldFiscalizeSale($finance, 1000, null, 'mobile'));
        $this->assertFalse(KraFiscalPolicy::shouldFiscalizeSale($finance, 1000, null, 'pos'));
        $this->assertFalse(KraFiscalPolicy::shouldFiscalizeSale($finance, 1000, null, 'backend'));
    }

    public function test_erp_scope_only_fiscalizes_pos_and_backoffice(): void
    {
        $finance = [
            'enable_kra_device' => true,
            'kra_sales_scope' => 'erp',
        ];

        $this->assertTrue(KraFiscalPolicy::shouldFiscalizeSale($finance, 1000, null, 'pos'));
        $this->assertTrue(KraFiscalPolicy::shouldFiscalizeSale($finance, 1000, null, 'backend'));
        $this->assertTrue(KraFiscalPolicy::shouldFiscalizeSale($finance, 1000, null, 'hotel_pos'));
        $this->assertFalse(KraFiscalPolicy::shouldFiscalizeSale($finance, 1000, null, 'mobile'));
    }

    public function test_both_scope_fiscalizes_all_channels(): void
    {
        $finance = [
            'enable_kra_device' => true,
            'kra_sales_scope' => 'both',
        ];

        $this->assertTrue(KraFiscalPolicy::shouldFiscalizeSale($finance, 1000, null, 'mobile'));
        $this->assertTrue(KraFiscalPolicy::shouldFiscalizeSale($finance, 1000, null, 'pos'));
        $this->assertTrue(KraFiscalPolicy::shouldFiscalizeSale($finance, 1000, null, 'backend'));
    }

    public function test_apply_sales_scope_keeps_legacy_boolean_in_sync(): void
    {
        $on = KraFiscalPolicy::applySalesScope([], 'erp');
        $this->assertSame('erp', $on['kra_sales_scope']);
        $this->assertTrue($on['default_submit_kra']);

        $off = KraFiscalPolicy::applySalesScope([], 'off');
        $this->assertSame('off', $off['kra_sales_scope']);
        $this->assertFalse($off['default_submit_kra']);
    }
}
