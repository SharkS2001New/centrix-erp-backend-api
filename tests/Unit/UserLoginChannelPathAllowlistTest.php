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

    public function test_mobile_channel_allows_as_shop_stock_receive_and_adjust(): void
    {
        $service = app(UserLoginChannelService::class);

        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/inventory/receive'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/inventory/adjust'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/stock-receipts'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/suppliers'));
        $this->assertFalse($service->tokenCanAccessPath('mobile', 'api/v1/inventory/transfer'));
        $this->assertFalse($service->tokenCanAccessPath('mobile', 'api/v1/users'));
    }

    public function test_mobile_channel_allows_hotel_bar_pos_paths(): void
    {
        $service = app(UserLoginChannelService::class);

        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/hospitality/pos/catalog'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/hospitality/pos/settings'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/hospitality/pos/checks'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/hospitality/pos/checks/1/settle'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/hospitality/floor-tables'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/hospitality/folios/open'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/hospitality/folios/12/payments'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/hospitality/checks'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/hospitality/checks/9'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/hospitality/outlets'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/stock-take-sessions'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/stock-take-lines'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/inventory/stock-take/1/initialize'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/categories'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/sales'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/reports/hospitality-fnb-checks'));
        $this->assertTrue($service->tokenCanAccessPath('mobile', 'api/v1/reports/hospitality-eod-report'));
        $this->assertFalse($service->tokenCanAccessPath('mobile', 'api/v1/hospitality/settings'));
        $this->assertFalse($service->tokenCanAccessPath('mobile', 'api/v1/hospitality/rooms'));
        $this->assertFalse($service->tokenCanAccessPath('mobile', 'api/v1/hospitality/front-desk/board'));
    }
}
