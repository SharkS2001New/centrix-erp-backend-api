<?php

namespace Tests\Unit\Sales;

use App\Services\Sales\PosLinePricingService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PosLinePricingServiceTierTest extends TestCase
{
    protected PosLinePricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = new PosLinePricingService;
    }

    /** @param  list<array<string, mixed>>  $tiers */
    protected function linePrice(
        float $baseUnitPrice,
        array $tiers,
        float $qty,
        bool $isRetail,
        float $conversion,
        float $middleFactor,
    ): float {
        $method = new ReflectionMethod(PosLinePricingService::class, 'linePrice');
        $method->setAccessible(true);

        return (float) $method->invoke(
            $this->pricing,
            $baseUnitPrice,
            $tiers,
            $qty,
            $isRetail,
            $conversion,
            $middleFactor,
            null,
        );
    }

    public function test_sugar_tiers_switch_markup_after_small_band(): void
    {
        $tiers = [
            [
                'min_qty' => 1.0,
                'max_qty' => 12.0,
                'measure_level' => 'small',
                'price_mode' => 'retail',
                'markup_price' => 6.0,
            ],
            [
                'min_qty' => 12.5,
                'max_qty' => 49.0,
                'measure_level' => 'small',
                'price_mode' => 'wholesale',
                'markup_price' => 30.0,
            ],
        ];

        // Tier 1: (6200/50 + 6) × qty
        $this->assertSame(130.0, $this->linePrice(6200, $tiers, 1, true, 50, 25));
        $this->assertSame(1560.0, $this->linePrice(6200, $tiers, 12, true, 50, 25));

        // Tier 2: aggregate wholesale + 30 per 25kg chunk (not tier-1 6/kg)
        $this->assertSame(1642.0, $this->linePrice(6200, $tiers, 13, true, 50, 25));
        $this->assertSame(2138.0, $this->linePrice(6200, $tiers, 17, true, 50, 25));
        $this->assertSame(3130.0, $this->linePrice(6200, $tiers, 25, true, 50, 25));
        $this->assertSame(5640.0, $this->linePrice(6200, $tiers, 45, true, 50, 25));

        // 45kg must not collapse to the 1kg retail total / rate.
        $this->assertNotEquals(
            $this->linePrice(6200, $tiers, 1, true, 50, 25),
            $this->linePrice(6200, $tiers, 45, true, 50, 25),
        );
        $this->assertNotEquals(
            130.0,
            round($this->linePrice(6200, $tiers, 45, true, 50, 25) / 45, 2),
        );

        // Gap between 12 and 12.5 must use the next (wholesale) band, not tier-1 /kg.
        $this->assertSame(1542.8, $this->linePrice(6200, $tiers, 12.2, true, 50, 25));
        $this->assertNotEquals(130.0 * 12.2, $this->linePrice(6200, $tiers, 12.2, true, 50, 25));
    }

    public function test_polished_half_bag_uses_zero_markup_tier(): void
    {
        $tiers = [
            [
                'min_qty' => 1.0,
                'max_qty' => 44.0,
                'measure_level' => 'small',
                'price_mode' => 'retail',
                'markup_price' => 2.777,
            ],
            [
                'min_qty' => 45.0,
                'max_qty' => 90.0,
                'measure_level' => 'full',
                'price_mode' => 'retail',
                'markup_price' => 0.0,
            ],
        ];

        $this->assertSame(95.0, $this->linePrice(8300, $tiers, 1, true, 90, 45));
        $this->assertSame(4150.0, $this->linePrice(8300, $tiers, 45, true, 90, 45));
        $this->assertSame(8300.0, $this->linePrice(8300, $tiers, 90, true, 90, 45));

        // Must not keep applying 2.777/kg into the half-bag band.
        $this->assertNotEquals(
            round((8300 / 90 + 2.777) * 45, 2),
            $this->linePrice(8300, $tiers, 45, true, 90, 45),
        );
    }
}
