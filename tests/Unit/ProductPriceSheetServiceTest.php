<?php

namespace Tests\Unit;

use App\Services\Catalog\ProductPriceSheetService;
use PHPUnit\Framework\TestCase;

class ProductPriceSheetServiceTest extends TestCase
{
    public function test_build_row_computes_wholesale_margin_from_cost(): void
    {
        $service = new ProductPriceSheetService;

        $product = (object) [
            'product_code' => 'P001',
            'product_name' => 'Sample Product',
            'unit_price' => 1000.0,
            'last_cost_price' => 930.0,
            'sell_on_retail' => 0,
        ];
        $uom = (object) [
            'uom_type' => 'carton',
            'conversion_factor' => 24,
            'measure_name' => 'carton',
            'middle_factor' => 12,
            'small_packaging_label' => 'pcs',
        ];

        $row = $service->buildRow(
            $product,
            $uom,
            null,
            'Biscuits',
            'Groceries',
            false,
        );

        $this->assertSame('P001', $row['product_code']);
        $this->assertSame('Sample Product', $row['product_name']);
        $this->assertSame('1 x 24pcs', $row['packaging']);
        $this->assertSame(930.0, $row['unit_cost']);
        $this->assertSame(1000.0, $row['wholesale_price']);
        $this->assertSame(7, $row['wholesale_margin']);
    }
}
