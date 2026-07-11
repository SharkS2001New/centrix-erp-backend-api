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

    public function test_wholesale_line_display_unit_price_uses_stored_display_price(): void
    {
        $product = new Product([
            'product_code' => 'TEST-003',
            'product_name' => 'Sugar 50kg',
            'unit_price' => 99,
        ]);
        $product->setRelation('unit', new Uom([
            'conversion_factor' => 50,
            'full_name' => 'Bag',
            'measure_name' => 'kg',
            'small_packaging_label' => 'kg',
        ]));

        $service = new SaleLineQuantityDisplayService();
        $display = $service->displayUnitPrice(100, 2400, $product, false, 100.0, 25.0, 48.0);

        $this->assertSame(48.0, $display);
    }

    public function test_wholesale_line_display_unit_price_recovers_from_amount_when_no_stored_display(): void
    {
        $product = new Product([
            'product_code' => 'TEST-003b',
            'product_name' => 'Sugar 50kg',
            'unit_price' => 99,
        ]);
        $product->setRelation('unit', new Uom([
            'conversion_factor' => 50,
            'full_name' => 'Bag',
            'measure_name' => 'kg',
            'small_packaging_label' => 'kg',
        ]));

        $service = new SaleLineQuantityDisplayService();
        // 100 base / 50 = 2 packs; (2400 + 100) / 2 = 1250 per pack
        $this->assertSame(1250.0, $service->displayUnitPrice(100, 2400, $product, false, 100.0, 25.0));
    }

    public function test_display_discount_per_unit_uses_entry_qty(): void
    {
        $product = new Product([
            'product_code' => 'TEST-004',
            'product_name' => 'Sugar 50kg',
        ]);
        $product->setRelation('unit', new Uom([
            'conversion_factor' => 50,
            'full_name' => 'Bag',
            'measure_name' => 'kg',
            'small_packaging_label' => 'kg',
        ]));

        $service = new SaleLineQuantityDisplayService();
        // 100 base = 2 packs; 100 discount / 2 = 50 per pack
        $this->assertSame(50.0, $service->displayDiscountPerUnit(100, 100.0, $product, false));
    }

    public function test_display_line_amount_subtracts_discount_from_gross_total(): void
    {
        $product = new Product([
            'product_code' => 'RICE25',
            'product_name' => 'Rice 25kg',
            'unit_price' => 91.44,
        ]);
        $product->setRelation('unit', new Uom([
            'conversion_factor' => 25,
            'full_name' => 'Bag',
            'measure_name' => 'kg',
            'small_packaging_label' => 'kg',
        ]));

        $service = new SaleLineQuantityDisplayService();
        $amount = $service->displayLineAmount(25, 2272, $product, false, 14.0, 91.44);

        $this->assertSame(2272.0, $amount);
        $this->assertSame(91.44, $service->displayUnitPrice(25, 2272, $product, false, 14.0, 91.44, 91.44));
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
