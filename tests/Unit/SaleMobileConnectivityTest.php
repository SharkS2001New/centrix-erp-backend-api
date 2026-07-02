<?php

namespace Tests\Unit;

use App\Models\Sale;
use PHPUnit\Framework\TestCase;

class SaleMobileConnectivityTest extends TestCase
{
    public function test_non_mobile_sale_has_no_connectivity(): void
    {
        $sale = new Sale([
            'channel' => 'pos',
            'fulfillment_meta' => [
                'location_check' => ['offline_order' => true],
            ],
        ]);

        $this->assertNull($sale->mobileOrderConnectivity());
        $this->assertFalse($sale->isOfflineMobileOrder());
    }

    public function test_mobile_online_order(): void
    {
        $sale = new Sale([
            'channel' => 'mobile',
            'fulfillment_meta' => [
                'location_check' => ['offline_order' => false, 'verified' => true],
            ],
        ]);

        $this->assertSame('online', $sale->mobileOrderConnectivity());
        $this->assertFalse($sale->isOfflineMobileOrder());
    }

    public function test_mobile_offline_order(): void
    {
        $sale = new Sale([
            'channel' => 'mobile',
            'fulfillment_meta' => [
                'location_check' => ['offline_order' => true, 'verified' => false],
            ],
        ]);

        $this->assertSame('offline', $sale->mobileOrderConnectivity());
        $this->assertTrue($sale->isOfflineMobileOrder());
    }

    public function test_mobile_order_without_location_check_is_unknown(): void
    {
        $sale = new Sale([
            'channel' => 'mobile',
            'fulfillment_meta' => [],
        ]);

        $this->assertNull($sale->mobileOrderConnectivity());
        $this->assertFalse($sale->isOfflineMobileOrder());
    }
}
