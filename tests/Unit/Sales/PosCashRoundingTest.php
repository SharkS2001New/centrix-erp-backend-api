<?php

namespace Tests\Unit\Sales;

use App\Services\Sales\PosCashRounding;
use Tests\TestCase;

class PosCashRoundingTest extends TestCase
{
    public function test_rounds_last_digit_like_light_stores(): void
    {
        $this->assertSame(106.0, PosCashRounding::roundLightStoresAmount(105.4));
        $this->assertSame(9000.0, PosCashRounding::roundLightStoresAmount(8998.0));
    }

    public function test_order_total_sums_rounded_lines_then_rounds_again(): void
    {
        $this->assertSame(
            9000.0,
            PosCashRounding::orderTotalFromLineAmounts([2999.0, 2999.0, 2999.0]),
        );
    }
}
