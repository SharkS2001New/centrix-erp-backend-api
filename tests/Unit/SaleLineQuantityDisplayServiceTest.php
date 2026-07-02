<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Uom;
use App\Services\Sales\SaleLineQuantityDisplayService;
use PHPUnit\Framework\TestCase;

class SaleLineQuantityDisplayServiceTest extends TestCase
{
    public function test_wholesale_line_shows_pack_entry_qty_not_base_qty(): void
    {
        $product = new Product([
            'product_code' => 'TEST-001',
            'product_name' => 'Sugar 50kg',
        ]);
        $product->setRelation('unit', new Uom([
            'conversion_factor' => 50,
            'full_name' => 'Bag',
            'measure_name' => 'kg',
            'small_packaging_label' => 'kg',
        ]));

        $display = (new SaleLineQuantityDisplayService())->formatLineQtyDisplay(100, $product, false, 'Bag');

        $this->assertSame('2 Bag', $display);
    }

    public function test_retail_line_with_base_qty_shows_pieces(): void
    {
        $product = new Product([
            'product_code' => 'TEST-002',
            'product_name' => 'Soda',
        ]);
        $product->setRelation('unit', new Uom([
            'conversion_factor' => 24,
            'full_name' => 'Carton',
            'measure_name' => 'piece',
            'small_packaging_label' => 'piece',
        ]));

        $display = (new SaleLineQuantityDisplayService())->formatLineQtyDisplay(2, $product, true, 'piece');

        $this->assertSame('2 piece', $display);
    }
}
