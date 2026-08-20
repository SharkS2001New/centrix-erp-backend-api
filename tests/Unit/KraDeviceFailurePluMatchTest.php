<?php

namespace Tests\Unit;

use App\Services\Kra\KraDeviceService;
use ReflectionMethod;
use Tests\TestCase;

class KraDeviceFailurePluMatchTest extends TestCase
{
    public function test_generic_337_does_not_list_every_sale_line_as_missing(): void
    {
        $service = KraDeviceService::fromSettings([
            'kra_device_serial' => 'TESTSN',
            'kra_shop_pin' => 'P000000000A',
            'kra_device_ip' => 'http://127.0.0.1:8080',
            'kra_device_test_mode' => true,
        ]);

        $payload = [
            'plu_data' => [
                ['item_Name' => 'BANJAB RICE 25KG', 'Barcode' => '', 'product_code' => 'RICE25'],
                ['item_Name' => 'STAR BIRIYANI', 'Barcode' => '', 'product_code' => 'STAR1'],
                ['item_Name' => 'COSMO HB 1KG', 'Barcode' => '', 'product_code' => 'COSMO1'],
            ],
        ];

        $method = new ReflectionMethod(KraDeviceService::class, 'deviceFailureResult');
        $method->setAccessible(true);
        $result = $method->invoke($service, 'E337: NO FIND PLU DATA', $payload);

        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('BANJAB RICE', (string) $result['message']);
        $this->assertStringNotContainsString('STAR BIRIYANI', (string) $result['message']);
        $this->assertStringContainsString('not found on the KRA device', (string) $result['message']);
    }

    public function test_named_device_sku_maps_to_product_code_label(): void
    {
        $service = KraDeviceService::fromSettings([
            'kra_device_serial' => 'TESTSN',
            'kra_shop_pin' => 'P000000000A',
            'kra_device_ip' => 'http://127.0.0.1:8080',
            'kra_device_test_mode' => true,
        ]);

        $payload = [
            'plu_data' => [
                ['item_Name' => 'BANJAB RICE 25KG', 'Barcode' => '', 'product_code' => 'RICE25'],
                ['item_Name' => 'STAR BIRIYANI', 'Barcode' => '', 'product_code' => 'STAR1'],
            ],
        ];

        $method = new ReflectionMethod(KraDeviceService::class, 'deviceFailureResult');
        $method->setAccessible(true);
        $result = $method->invoke($service, 'E337: NO FIND PLU DATA for item 000000STAR1', $payload);

        $this->assertStringContainsString('STAR BIRIYANI (STAR1)', (string) $result['message']);
        $this->assertStringNotContainsString('BANJAB RICE', (string) $result['message']);
    }
}
