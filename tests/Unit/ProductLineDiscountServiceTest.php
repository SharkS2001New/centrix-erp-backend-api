<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Sales\ProductLineDiscountService;
use Tests\TestCase;

class ProductLineDiscountServiceTest extends TestCase
{
    public function test_percentage_product_discount(): void
    {
        $service = app(ProductLineDiscountService::class);
        $product = new Product([
            'discount_type' => 'percentage',
            'discount_percentage' => 10,
        ]);

        $this->assertTrue($service->productHasConfiguredDiscount($product));
        $this->assertSame(20.0, $service->computeProductLineDiscount($product, 200, 1));
    }

    public function test_fixed_product_discount_scales_by_pack_qty(): void
    {
        $service = app(ProductLineDiscountService::class);
        $product = new Product([
            'discount_type' => 'fixed',
            'discount_value' => 5,
        ]);

        $this->assertTrue($service->productHasConfiguredDiscount($product));
        $this->assertSame(15.0, $service->computeProductLineDiscount($product, 300, 3));
    }

    public function test_percentage_discount_from_line_amount(): void
    {
        $product = new Product([
            'discount_type' => 'percentage',
            'discount_percentage' => 10,
        ]);
        $service = app(ProductLineDiscountService::class);

        $this->assertSame(40.0, $service->computeProductLineDiscount($product, 400, 1));
    }
}
