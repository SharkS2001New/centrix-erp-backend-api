<?php

namespace Tests\Unit;

use App\Services\Auth\UserLoginChannelService;
use Tests\TestCase;

class UserLoginChannelPathAllowlistTest extends TestCase
{
    public function test_pos_channel_allows_reference_pickers_used_by_pos_ui(): void
    {
        $service = app(UserLoginChannelService::class);

        $this->assertTrue($service->tokenCanAccessPath('pos', 'api/v1/reference/vats'));
        $this->assertTrue($service->tokenCanAccessPath('pos', 'api/v1/reference/uoms'));
        $this->assertTrue($service->tokenCanAccessPath('pos', 'reference/categories'));
        $this->assertTrue($service->tokenCanAccessPath('pos', 'uoms'));
        $this->assertTrue($service->tokenCanAccessPath('pos', 'vats'));
        $this->assertFalse($service->tokenCanAccessPath('pos', 'api/v1/users'));
        $this->assertFalse($service->tokenCanAccessPath('pos', 'admin/roles'));
    }

    public function test_mobile_channel_allows_reference_pickers(): void
    {
        $service = app(UserLoginChannelService::class);

        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/reference/uoms'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/reference/vats'));
    }
}
