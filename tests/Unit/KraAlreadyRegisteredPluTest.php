<?php

namespace Tests\Unit;

use App\Services\Kra\KraDeviceService;
use Tests\TestCase;

class KraAlreadyRegisteredPluTest extends TestCase
{
    public function test_detects_e353_same_name_as_already_registered(): void
    {
        $service = KraDeviceService::fromSettings([
            'kra_device_ip' => 'http://192.168.1.50:8010',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
        ]);

        $this->assertTrue($service->isAlreadyRegisteredPluResult([
            'success' => false,
            'message' => 'E353: THE SAME NAME',
        ]));

        $this->assertTrue($service->isAlreadyRegisteredPluResult([
            'success' => false,
            'message' => 'A product with this name is already registered on the KRA device.',
        ]));

        $this->assertTrue($service->isAlreadyRegisteredPluResult([
            'success' => false,
            'message' => '1 product was already on the KRA device (skipped).',
        ]));

        $this->assertFalse($service->isAlreadyRegisteredPluResult([
            'success' => false,
            'message' => 'E337: NO FIND PLU DATA',
        ]));
    }
}
