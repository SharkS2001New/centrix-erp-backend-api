<?php

namespace Tests\Unit;

use App\Services\Inventory\StockCostCalculation;
use PHPUnit\Framework\TestCase;

class StockCostCalculationTest extends TestCase
{
    public function test_line_cost_converts_base_qty_to_pack_cost(): void
    {
        // 24 base units at KES 1,200 per pack of 24 => KES 1,200
        $this->assertSame(1200.0, StockCostCalculation::lineCostFromBaseQuantity(24, 1200, 24));
        // Without conversion (factor 1): 24 × 1200 would be wrong for pack cost — factor 1 means cost is per base
        $this->assertSame(2400.0, StockCostCalculation::lineCostFromBaseQuantity(24, 100, 1));
    }

    public function test_cost_value_sql_divides_by_conversion_factor(): void
    {
        $sql = StockCostCalculation::costValueSqlExpression(
            'it.quantity_change',
            'COALESCE(it.unit_cost, 0)',
            'u',
        );

        $this->assertStringContainsString('it.quantity_change', $sql);
        $this->assertStringContainsString('u.conversion_factor', $sql);
        $this->assertStringContainsString('/', $sql);
    }
}
