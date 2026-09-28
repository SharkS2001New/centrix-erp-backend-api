<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiToolResultReplyBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AiProductDetailsReplyTest extends TestCase
{
    public function test_format_product_details_keeps_full_stock_unit_labels(): void
    {
        $builder = new AiToolResultReplyBuilder;
        $method = new ReflectionMethod(AiToolResultReplyBuilder::class, 'formatProductDetails');

        $reply = $method->invoke($builder, [
            'product' => [
                'product_code' => 'TF10',
                'product_name' => 'TOP FRY 10L',
                'unit_price' => 2270,
                'last_cost_price' => null,
                'selling_unit_label' => 'jerrican',
                'sell_on_retail' => false,
            ],
            'vat' => [
                'configured' => true,
                'label' => 'Zero-rated (0%)',
                'is_zero_rated' => true,
            ],
            'measurements' => [
                'configured' => true,
                'conversion_meaning' => '1 jerrican = 1 jerrican (base units)',
                'full_package_label' => 'jerrican',
                'small_package_label' => 'jerrican',
            ],
            'stock' => [
                'shop_qty_label' => '0 jerrican',
                'store_qty_label' => '0 jerrican',
                'total_qty_label' => '0 jerrican',
            ],
            'retail_packaging' => [],
        ]);

        $this->assertIsString($reply);
        $this->assertStringContainsString('KES 2,270.00', $reply);
        $this->assertStringContainsString('0 jerrican', $reply);
        $this->assertStringNotContainsString('| 0 jer |', $reply);
        $this->assertStringContainsString('Zero-rated (0%)', $reply);
        $this->assertStringContainsString('Cost price: **not set**', $reply);
        $this->assertStringContainsString('| Shop | 0 jerrican |', $reply);
        $this->assertStringContainsString('| Store | 0 jerrican |', $reply);
    }
}
