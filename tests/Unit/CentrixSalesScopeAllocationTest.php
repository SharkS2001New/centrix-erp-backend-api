<?php

namespace Tests\Unit;

use App\Services\Sales\CentrixSalesScope;
use PHPUnit\Framework\TestCase;

class CentrixSalesScopeAllocationTest extends TestCase
{
    public function test_scale_vat_for_order_discount_keeps_proportion(): void
    {
        $scaled = CentrixSalesScope::scaleVatForOrderDiscount(1000.0, 137.93, 100.0);

        $this->assertSame(100.0, $scaled['order_discount']);
        $this->assertSame(900.0, $scaled['order_total']);
        $this->assertEqualsWithDelta(124.14, $scaled['total_vat'], 0.01);
    }

    public function test_scale_vat_noop_without_discount(): void
    {
        $scaled = CentrixSalesScope::scaleVatForOrderDiscount(500.0, 68.97, 0.0);

        $this->assertEquals(500.0, $scaled['order_total']);
        $this->assertEquals(68.97, $scaled['total_vat']);
        $this->assertEquals(0.0, $scaled['order_discount']);
    }
}
