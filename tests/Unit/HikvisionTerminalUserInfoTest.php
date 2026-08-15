<?php

namespace Tests\Unit;

use App\Services\Attendance\Hikvision\HikvisionService;
use Tests\TestCase;

class HikvisionTerminalUserInfoTest extends TestCase
{
    public function test_terminal_user_info_enables_local_fingerprint_enrollment(): void
    {
        $info = HikvisionService::terminalUserInfo('0003', 'Jane Doe');

        $this->assertSame('0003', $info['employeeNo']);
        $this->assertSame('Jane Doe', $info['name']);
        $this->assertSame('normal', $info['userType']);
        $this->assertTrue($info['localUIRight']);
        $this->assertSame('1', $info['doorRight']);
        $this->assertSame(1, $info['RightPlan'][0]['doorNo']);
        $this->assertSame('1', $info['RightPlan'][0]['planTemplateNo']);
        $this->assertTrue($info['Valid']['enable']);
        $this->assertSame('local', $info['Valid']['timeType']);
        $this->assertSame('2000-01-01T00:00:00', $info['Valid']['beginTime']);
        $this->assertSame('2037-12-31T23:59:59', $info['Valid']['endTime']);
    }

    public function test_terminal_user_info_merges_valid_overrides_without_dropping_rights(): void
    {
        $info = HikvisionService::terminalUserInfo('12', 'Sam', [
            'name' => 'Samuel',
            'Valid' => ['endTime' => '2030-01-01T00:00:00'],
        ]);

        $this->assertSame('Samuel', $info['name']);
        $this->assertTrue($info['localUIRight']);
        $this->assertSame('1', $info['doorRight']);
        $this->assertSame('2000-01-01T00:00:00', $info['Valid']['beginTime']);
        $this->assertSame('2030-01-01T00:00:00', $info['Valid']['endTime']);
    }

    public function test_device_user_already_exists_is_detected_from_hikvision_payload(): void
    {
        $this->assertTrue(HikvisionService::isDeviceUserAlreadyExistsError(
            new \RuntimeException('Hikvision device rejected the request (Invalid Content, deviceUserAlreadyExist, 0x60007002).'),
        ));
        $this->assertTrue(HikvisionService::isDeviceUserAlreadyExistsError(
            new \RuntimeException('Hikvision POST failed HTTP 400: {"subStatusCode":"deviceUserAlreadyExist"}'),
        ));
        $this->assertFalse(HikvisionService::isDeviceUserAlreadyExistsError(
            new \RuntimeException('Hikvision device rejected the request (Invalid Content, deviceUserNotExist, 0x60007001).'),
        ));
    }
}
