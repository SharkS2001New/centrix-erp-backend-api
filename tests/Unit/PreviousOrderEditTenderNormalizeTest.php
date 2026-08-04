<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\Operations\CheckoutController;
use ReflectionMethod;
use Tests\TestCase;

class PreviousOrderEditTenderNormalizeTest extends TestCase
{
    public function test_scales_prior_mix_down_to_new_total(): void
    {
        $controller = app(CheckoutController::class);
        $method = new ReflectionMethod(CheckoutController::class, 'normalizeTenderMapToTotal');
        $method->setAccessible(true);

        $scaled = $method->invoke($controller, [
            'EQUITY' => 20000.0,
        ], 10000.0);

        $this->assertSame(10000.0, round(array_sum($scaled), 2));
        $this->assertEqualsWithDelta(10000.0, (float) ($scaled['EQUITY'] ?? 0), 0.01);
    }

    public function test_applies_return_then_matches_new_total(): void
    {
        $controller = app(CheckoutController::class);
        $normalize = new ReflectionMethod(CheckoutController::class, 'normalizeTenderMapToTotal');
        $normalize->setAccessible(true);

        $map = ['EQUITY' => 20000.0];
        $map['EQUITY'] = max(0, $map['EQUITY'] - 10000.0);
        $map = array_filter($map, static fn (float $a) => $a > 0.009);
        $normalized = $normalize->invoke($controller, $map, 10000.0);

        $this->assertEqualsWithDelta(10000.0, (float) ($normalized['EQUITY'] ?? 0), 0.01);
    }

    public function test_mixed_methods_scale_proportionally(): void
    {
        $controller = app(CheckoutController::class);
        $method = new ReflectionMethod(CheckoutController::class, 'normalizeTenderMapToTotal');
        $method->setAccessible(true);

        $scaled = $method->invoke($controller, [
            'EQUITY' => 15000.0,
            'CASH' => 5000.0,
        ], 10000.0);

        $this->assertSame(10000.0, round(array_sum($scaled), 2));
        $this->assertEqualsWithDelta(7500.0, (float) ($scaled['EQUITY'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(2500.0, (float) ($scaled['CASH'] ?? 0), 0.02);
    }

    public function test_empty_tender_map_stays_empty_instead_of_inventing_cash(): void
    {
        $controller = app(CheckoutController::class);
        $method = new ReflectionMethod(CheckoutController::class, 'normalizeTenderMapToTotal');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke($controller, [], 15000.0));
    }
}
