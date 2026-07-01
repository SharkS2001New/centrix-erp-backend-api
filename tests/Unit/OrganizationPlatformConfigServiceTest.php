<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Services\OrganizationPlatformConfigService;
use Tests\TestCase;

class OrganizationPlatformConfigServiceTest extends TestCase
{
    public function test_sales_platform_config_returns_stock_deduct_on_as_channel_map(): void
    {
        $org = new Organization([
            'enabled_modules' => ['sales.pos' => true],
            'module_settings' => [
                'sales' => [
                    'show_checkout_on_create_order' => true,
                    'stock_deduct_on' => [
                        'pos' => 'order_created',
                        'mobile' => 'order_completed',
                        'backend' => 'order_completed',
                    ],
                    'orders_list_default_days' => 7,
                    'orders_list_sort' => '-order_num',
                ],
                'finance' => [],
                'ai' => [],
                'admin' => [],
                'inventory' => [],
            ],
        ]);

        $config = app(OrganizationPlatformConfigService::class)->salesPlatformConfigForOrganization($org);

        $this->assertIsArray($config['stock_deduct_on']);
        $this->assertSame('order_created', $config['stock_deduct_on']['pos']);
        $this->assertSame('order_completed', $config['stock_deduct_on']['mobile']);
        $this->assertSame(7, $config['orders_list_default_days']);
        $this->assertSame('-order_num', $config['orders_list_sort']);
        $this->assertTrue($config['order_cancellation_enabled']);
    }
}
