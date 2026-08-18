<?php

namespace Tests\Unit\Sales;

use App\Services\Sales\CartLineAmountResolver;
use PHPUnit\Framework\TestCase;

class CartLineAmountResolverTest extends TestCase
{
    public function test_keeps_server_amount_when_client_omits_amount(): void
    {
        $this->assertSame(3600.0, CartLineAmountResolver::resolve(null, 3600));
    }

    public function test_accepts_small_cash_rounding_nudge(): void
    {
        $this->assertSame(90.0, CartLineAmountResolver::resolve(90, 89));
    }

    public function test_rejects_inflated_client_total_from_pack_price_bug(): void
    {
        // 1 bag @ 3600 miscomputed as 3600×25 + markup 2000.
        $this->assertSame(3600.0, CartLineAmountResolver::resolve(92000, 3600));
    }

    public function test_accepts_workspace_line_amount_lower_than_miscomputed_total(): void
    {
        // Tier-priced POS line: workspace 3600, naive unit×qty recompute 92000.
        $this->assertSame(3600.0, CartLineAmountResolver::resolve(3600, 92000));
    }

    public function test_accepts_matching_client_amount(): void
    {
        $this->assertSame(3155.0, CartLineAmountResolver::resolve(3155, 3155));
    }

    public function test_keeps_wholesale_retail_package_markup_above_catalog(): void
    {
        // Wimbi sold at 110/kg with catalog 100 — hold/restore must not strip markup.
        $this->assertSame(110.0, CartLineAmountResolver::resolve(110, 100));
        $this->assertSame(220.0, CartLineAmountResolver::resolve(220, 200, 25));
    }

    public function test_rejects_pack_price_times_base_qty_when_conversion_is_known(): void
    {
        $this->assertSame(3600.0, CartLineAmountResolver::resolve(90000, 3600, 25));
    }
}
