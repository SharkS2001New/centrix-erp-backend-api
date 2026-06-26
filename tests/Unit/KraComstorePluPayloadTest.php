<?php

namespace Tests\Unit;

use App\Services\Kra\KraDeviceService;
use Tests\TestCase;

class KraComstorePluPayloadTest extends TestCase
{
    public function test_build_comstore_plu_item_matches_documentation_shape(): void
    {
        $product = (object) [
            'id' => 17,
            'product_code' => '17',
            'product_name' => 'Test Item',
            'unit_price' => 1.0,
            'stock_in_shop' => 0,
            'stock_in_store' => 0,
            'vat' => (object) ['vat_percentage' => 16, 'vat_name' => 'Standard Rated'],
        ];

        $item = KraDeviceService::buildComstorePluItemFromProduct($product);

        $this->assertSame('17', $item['plu_no']);
        $this->assertSame('17', $item['barcode']);
        $this->assertSame('Test Item', $item['plu_name']);
        $this->assertSame('1.00', $item['unit_price']);
        $this->assertSame('99010000', $item['item_cls_code']);
        $this->assertSame('BG-Bag', $item['pkg_unit_cd']);
        $this->assertSame('KE-KENYA', $item['orgn_nat_cd']);
        $this->assertSame('B', $item['tax_type']);
        $this->assertSame('02Finished Product', $item['type_code']);
        $this->assertSame('1', $item['use_yor_n']);
    }

    public function test_sanitize_comstore_plu_name_strips_special_characters(): void
    {
        $product = (object) [
            'id' => 33,
            'product_code' => '33',
            'product_name' => 'orange & mango #1',
            'unit_price' => 44,
            'stock_in_shop' => 6,
            'stock_in_store' => 0,
            'vat' => (object) ['vat_percentage' => 0, 'vat_name' => 'Zero Rated'],
        ];

        $item = KraDeviceService::buildComstorePluItemFromProduct($product);

        $this->assertSame('orange mango 1', $item['plu_name']);
        $this->assertSame('C', $item['tax_type']);
        $this->assertSame('6', $item['stocks']);
    }
}
