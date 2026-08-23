<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiSettingsResolver;
use App\Services\Ai\AiToolRegistry;
use Tests\TestCase;

class AiToolRegistryTest extends TestCase
{
    public function test_registry_includes_top_level_agent_tools(): void
    {
        $names = array_map(
            fn ($tool) => $tool->name(),
            app(AiToolRegistry::class)->all(),
        );

        foreach ([
            'find_screen',
            'get_sales_summary',
            'get_sales_by_cashier',
            'get_sales_brief',
            'get_stock_summary',
            'get_purchasing_overview',
            'get_debtors_summary',
            'get_till_health',
            'get_route_orders',
        ] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function test_normalize_tools_defaults_to_config(): void
    {
        $tools = AiSettingsResolver::normalizeTools([]);
        $this->assertTrue($tools['get_sales_summary'] ?? false);
        $this->assertTrue($tools['get_debtors_summary'] ?? false);
        $this->assertTrue($tools['get_till_health'] ?? false);
    }

    public function test_normalize_tools_can_disable_individual_tools(): void
    {
        $tools = AiSettingsResolver::normalizeTools([
            'get_route_orders' => false,
        ]);
        $this->assertFalse($tools['get_route_orders']);
        $this->assertTrue($tools['get_sales_summary']);
    }
}
