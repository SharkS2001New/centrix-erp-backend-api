<?php

namespace Tests\Unit;

use App\Services\Kra\SalesVatCalculator;
use PHPUnit\Framework\TestCase;

class SalesVatCalculatorTest extends TestCase
{
    public function test_vat_from_inclusive_gross_at_sixteen_percent(): void
    {
        $this->assertEquals(13.79, SalesVatCalculator::vatFromInclusiveGross(100, 16));
        $this->assertEquals(86.21, SalesVatCalculator::netFromInclusiveGross(100, 16));
    }

    public function test_zero_rate_returns_zero_vat(): void
    {
        $this->assertEquals(0.0, SalesVatCalculator::vatFromInclusiveGross(100, 0));
    }
}
