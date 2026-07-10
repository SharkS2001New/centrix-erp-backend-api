<?php

namespace Tests\Unit;

use App\Services\OrganizationProvisioningService;
use Tests\TestCase;

class OrganizationProvisioningChannelsTest extends TestCase
{
    protected OrganizationProvisioningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrganizationProvisioningService::class);
    }

    public function test_distribution_modules_expose_backoffice_and_mobile_only(): void
    {
        $modules = [
            'sales.backend' => true,
            'sales.mobile' => true,
            'sales.pos' => false,
            'distribution' => true,
        ];

        $this->assertSame(['mobile', 'backend'], $this->service->salesChannelsFromEnabledModules($modules));
        $this->assertEqualsCanonicalizing(
            ['backoffice', 'mobile', 'manager'],
            $this->service->loginChannelsFromEnabledModules($modules),
        );
    }

    public function test_external_pos_adds_pos_sales_and_login_channels(): void
    {
        $modules = [
            'sales.backend' => true,
            'sales.mobile' => true,
            'sales.pos' => true,
        ];

        $this->assertSame(['pos', 'mobile', 'backend'], $this->service->salesChannelsFromEnabledModules($modules));
        $this->assertSame(['backoffice', 'pos', 'mobile', 'manager'], $this->service->loginChannelsFromEnabledModules($modules));
    }

    public function test_mobile_orders_disabled_hides_mobile_channel(): void
    {
        $modules = [
            'sales.backend' => true,
            'sales.mobile' => true,
            'sales.pos' => false,
        ];

        $this->assertSame(['backend'], $this->service->salesChannelsFromEnabledModules($modules, mobileOrdersEnabled: false));
        $this->assertSame(
            ['backoffice', 'manager'],
            $this->service->loginChannelsFromEnabledModules($modules, ['enable_mobile_orders' => false]),
        );
    }

    public function test_map_config_channels_to_login_channels(): void
    {
        $this->assertEqualsCanonicalizing(
            ['backoffice', 'mobile'],
            $this->service->mapConfigChannelsToLoginChannels(['mobile', 'backend']),
        );
    }
}
