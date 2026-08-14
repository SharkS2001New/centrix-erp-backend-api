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
        $this->assertSame('order_completed', $config['stock_deduct_on']['backend']);
        $this->assertSame(7, $config['orders_list_default_days']);
        $this->assertSame(30, $config['orders_list_search_days']);
        $this->assertSame('-order_num', $config['orders_list_sort']);
        $this->assertTrue($config['order_cancellation_enabled']);
    }

    public function test_default_sales_platform_config_varies_by_deployment_profile(): void
    {
        $service = app(OrganizationPlatformConfigService::class);

        $wholesale = $service->defaultSalesPlatformConfig('wholesale_retail');
        $this->assertSame(14, $wholesale['orders_list_default_days']);
        $this->assertSame(30, $wholesale['orders_list_search_days']);
        $this->assertSame('order_created', $wholesale['stock_deduct_on']['mobile']);
        $this->assertSame('order_created', $wholesale['stock_deduct_on']['backend']);

        $distribution = $service->defaultSalesPlatformConfig('distribution');
        $this->assertSame(30, $distribution['orders_list_default_days']);
        $this->assertSame(60, $distribution['orders_list_search_days']);
        $this->assertSame('order_completed', $distribution['stock_deduct_on']['mobile']);
        $this->assertSame('order_completed', $distribution['stock_deduct_on']['backend']);
    }

    public function test_default_sales_platform_includes_order_action_status_gates(): void
    {
        $config = app(OrganizationPlatformConfigService::class)->defaultSalesPlatformConfig('small_shop');

        $this->assertSame(['booked', 'pending', 'editable'], $config['edit_order_statuses']);
        $this->assertNull($config['print_invoice_statuses']);
        $this->assertSame(['unpaid', 'pending_payment'], $config['collect_payment_statuses']);
        $this->assertTrue($config['order_cancellation_enabled']);
        $this->assertSame(
            ['booked', 'pending', 'unpaid', 'pending_payment', 'paid', 'processed', 'delivered', 'completed'],
            $config['cancel_order_statuses'],
        );
        $this->assertSame(
            ['paid', 'processed', 'delivered', 'completed'],
            $config['customer_return_statuses'],
        );
    }

    public function test_sales_platform_config_reads_custom_action_status_lists(): void
    {
        $org = new Organization([
            'enabled_modules' => ['sales' => true],
            'module_settings' => [
                'sales' => [
                    'edit_order_statuses' => ['booked', 'unpaid'],
                    'print_invoice_statuses' => ['paid', 'completed'],
                    'collect_payment_statuses' => ['unpaid'],
                ],
            ],
        ]);

        $config = app(OrganizationPlatformConfigService::class)->salesPlatformConfigForOrganization($org);

        $this->assertSame(['booked', 'unpaid'], $config['edit_order_statuses']);
        $this->assertSame(['paid', 'completed'], $config['print_invoice_statuses']);
        $this->assertSame(['unpaid'], $config['collect_payment_statuses']);
    }

    public function test_normalize_action_statuses_fall_back_and_allow_empty_print(): void
    {
        $service = app(OrganizationPlatformConfigService::class);

        $this->assertSame(
            ['booked', 'pending', 'editable'],
            $service->normalizeRequiredActionStatuses([], ['booked', 'pending', 'editable']),
        );
        $this->assertSame(
            ['unpaid'],
            $service->normalizeRequiredActionStatuses(['unpaid', 'bogus', 'unpaid'], ['unpaid', 'pending_payment']),
        );
        $this->assertNull($service->normalizeOptionalActionStatuses(null));
        $this->assertNull($service->normalizeOptionalActionStatuses([]));
        $this->assertSame(['paid'], $service->normalizeOptionalActionStatuses(['paid', 'unknown']));
    }

    public function test_sales_platform_config_exposes_pos_ui_layouts(): void
    {
        $org = new Organization([
            'enabled_modules' => ['sales.pos' => true],
            'module_settings' => [
                'sales' => array_merge(
                    config('erp.module_settings_defaults.sales'),
                    [
                        'external_pos_layout' => 'classic',
                        'backoffice_order_edit_layout' => 'classic',
                    ],
                ),
            ],
        ]);

        $config = app(OrganizationPlatformConfigService::class)->salesPlatformConfigForOrganization($org);

        $this->assertSame('classic', $config['external_pos_layout']);
        $this->assertSame('classic', $config['backoffice_order_edit_layout']);
    }

    public function test_sales_platform_config_reads_pos_combine_identical_lines_false(): void
    {
        $service = app(OrganizationPlatformConfigService::class);

        $this->assertTrue($service->defaultSalesPlatformConfig()['pos_combine_identical_lines']);
        $this->assertContains('pos_combine_identical_lines', $service->platformControlledSalesKeys());
        $this->assertContains('receipt_show_all_payment_methods', $service->platformControlledSalesKeys());

        $org = new Organization([
            'enabled_modules' => ['sales.pos' => true],
            'module_settings' => [
                'sales' => array_merge(
                    config('erp.module_settings_defaults.sales'),
                    [
                        'pos_combine_identical_lines' => false,
                        'receipt_show_all_payment_methods' => false,
                    ],
                ),
            ],
        ]);

        $config = $service->salesPlatformConfigForOrganization($org);

        $this->assertFalse($config['pos_combine_identical_lines']);
        $this->assertFalse($config['receipt_show_all_payment_methods']);
    }
}
