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

    public function test_hr_only_org_gets_backoffice_and_manager_without_sales(): void
    {
        $modules = [
            'sales.backend' => false,
            'sales.pos' => false,
            'sales.mobile' => false,
            'hr_payroll' => true,
            'accounting' => false,
            'inventory' => false,
        ];

        $this->assertEqualsCanonicalizing(
            ['backoffice', 'manager'],
            $this->service->loginChannelsFromEnabledModules($modules),
        );
    }

    public function test_manager_disabled_for_sales_org_when_toggle_off(): void
    {
        $modules = [
            'sales.backend' => true,
            'sales.pos' => false,
            'sales.mobile' => false,
        ];

        $this->assertSame(
            ['backoffice'],
            $this->service->loginChannelsFromEnabledModules($modules, ['enable_manager_app' => false]),
        );
    }
}
