<?php

namespace Tests\Unit;

use App\Services\Kra\KraDeviceService;
use Tests\TestCase;

class KraDeviceHardwareIpTest extends TestCase
{
    public function test_prefers_explicit_hardware_ip(): void
    {
        $ip = KraDeviceService::resolveHardwareIp([
            'kra_device_hardware_ip' => '192.168.1.39',
            'kra_device_ip' => 'https://kramoonstores.example.test',
        ]);

        $this->assertSame('192.168.1.39', $ip);
    }

    public function test_falls_back_to_ip_host_from_api_url(): void
    {
        $ip = KraDeviceService::resolveHardwareIp([
            'kra_device_ip' => 'http://192.168.1.50:8010',
        ]);

        $this->assertSame('192.168.1.50', $ip);
    }

    public function test_returns_empty_for_hostname_api_url_without_explicit_hardware_ip(): void
    {
        $ip = KraDeviceService::resolveHardwareIp([
            'kra_device_ip' => 'https://kramoonstores.example.test',
        ]);

        $this->assertSame('', $ip);
    }
}
