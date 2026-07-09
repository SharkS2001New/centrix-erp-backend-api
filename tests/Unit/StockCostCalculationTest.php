<?php

namespace Tests\Unit;

use App\Services\Inventory\StockCostCalculation;
use PHPUnit\Framework\TestCase;

class StockCostCalculationTest extends TestCase
{
    public function test_line_cost_uses_converted_quantity_times_package_cost(): void
    {
        $this->assertSame(2400.0, StockCostCalculation::lineCostFromBaseQuantity(48, 1200, 24));
    }

    public function test_conversion_factor_defaults_to_one(): void
    {
        $this->assertSame(100.0, StockCostCalculation::lineCostFromBaseQuantity(10, 10, 0));
        $this->assertSame(100.0, StockCostCalculation::lineCostFromBaseQuantity(10, 10, null));
    }
}
