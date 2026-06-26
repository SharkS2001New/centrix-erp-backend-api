<?php

namespace Tests\Unit;

use App\Services\Kra\KraDeviceService;
use Tests\TestCase;

class KraComstorePluPayloadTest extends TestCase
{
    public function test_build_comstore_plu_item_matches_lightstores_upload_shape(): void
    {
        $product = (object) [
            'id' => 301,
            'product_code' => 'PRD#0001',
            'product_name' => 'Test Item',
            'unit_price' => 1.0,
            'vat' => (object) ['vat_percentage' => 16, 'vat_name' => 'Standard Rated'],
        ];

        $item = KraDeviceService::buildComstorePluItemFromProduct($product);

        $this->assertSame('301', $item['plu_no']);
        $this->assertSame('000000PRD#0001', $item['barcode']);
        $this->assertSame('Test Item', $item['plu_name']);
        $this->assertSame('1', $item['unit_price']);
        $this->assertSame('99010000', $item['item_cls_code']);
        $this->assertSame('BG-Bag', $item['pkg_unit_cd']);
        $this->assertSame('U-Pieces/item [Number]', $item['qty_unit_cd']);
        $this->assertSame('KE-KENYA', $item['orgn_nat_cd']);
        $this->assertSame('0', $item['btch_no']);
        $this->assertSame('B-16.00%', $item['tax_type']);
        $this->assertSame('0', $item['sfty_qty']);
        $this->assertSame('02Finished Product', $item['type_code']);
        $this->assertSame('100000', $item['change_qty']);
        $this->assertSame('0', $item['stocks']);
        $this->assertSame('1', $item['use_yor_n']);
    }

    public function test_exempt_vat_uses_a_exempt_tax_type(): void
    {
        $product = (object) [
            'id' => 33,
            'product_code' => '33',
            'product_name' => 'orange',
            'unit_price' => 44,
            'vat' => (object) ['vat_percentage' => 0, 'vat_name' => 'Exempt'],
        ];

        $item = KraDeviceService::buildComstorePluItemFromProduct($product);

        $this->assertSame('orange', $item['plu_name']);
        $this->assertSame('A-Exempt', $item['tax_type']);
        $this->assertSame('44', $item['unit_price']);
    }
}
