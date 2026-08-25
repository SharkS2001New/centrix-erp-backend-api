<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\Tools\CalculateScenarioTool;
use App\Services\Ai\Tools\GetCashPositionTool;
use App\Services\Ai\Tools\GetCustomerPortfolioTool;
use App\Services\Ai\Tools\GetExpenseSummaryTool;
use App\Services\Ai\Tools\GetInventoryValuationTool;
use App\Services\Ai\Tools\GetProfitLossTool;
use App\Services\Ai\Tools\RunInsightTool;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiBiToolsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_registry_includes_bi_tools(): void
    {
        $names = array_map(fn ($tool) => $tool->name(), app(AiToolRegistry::class)->all());

        $this->assertContains('run_insight', $names);
        $this->assertContains('get_profit_loss', $names);
        $this->assertContains('get_expense_summary', $names);
        $this->assertContains('get_customer_portfolio', $names);
        $this->assertContains('get_inventory_valuation', $names);
        $this->assertContains('get_cash_position', $names);
        $this->assertContains('calculate_scenario', $names);
    }

    public function test_get_profit_loss_returns_period_totals(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        /** @var GetProfitLossTool $tool */
        $tool = app(GetProfitLossTool::class);
        $result = $tool->execute($admin, ['relative_date' => 'this_month']);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('profit_loss', $result['type'] ?? null);
        $this->assertArrayHasKey('gross_revenue', $result['current'] ?? []);
        $this->assertArrayHasKey('gross_profit', $result['current'] ?? []);
        $this->assertArrayHasKey('net_profit', $result['current'] ?? []);
    }

    public function test_run_insight_forecast_light(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        /** @var RunInsightTool $tool */
        $tool = app(RunInsightTool::class);
        $result = $tool->execute($admin, [
            'insight_type' => 'forecast_light',
            'lookback_days' => 30,
        ]);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('forecast_light', $result['type'] ?? null);
        $this->assertSame('simple_daily_run_rate', $result['method'] ?? null);
    }

    public function test_run_insight_requires_customer_num_for_customer_360(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        /** @var RunInsightTool $tool */
        $tool = app(RunInsightTool::class);
        $result = $tool->execute($admin, ['insight_type' => 'customer_360']);

        $this->assertTrue($result['error'] ?? false);
    }

    public function test_get_expense_summary_returns_categories(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        /** @var GetExpenseSummaryTool $tool */
        $tool = app(GetExpenseSummaryTool::class);
        $result = $tool->execute($admin, ['relative_date' => 'this_month']);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('expense_summary', $result['type'] ?? null);
        $this->assertArrayHasKey('by_category', $result);
    }

    public function test_get_customer_portfolio_returns_lists(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        /** @var GetCustomerPortfolioTool $tool */
        $tool = app(GetCustomerPortfolioTool::class);
        $result = $tool->execute($admin, ['lookback_days' => 90]);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('customer_portfolio', $result['type'] ?? null);
        $this->assertArrayHasKey('top_by_revenue', $result);
        $this->assertArrayHasKey('inactive_customers', $result);
    }

    public function test_get_inventory_valuation_returns_summary(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        /** @var GetInventoryValuationTool $tool */
        $tool = app(GetInventoryValuationTool::class);
        $result = $tool->execute($admin, []);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('inventory_valuation', $result['type'] ?? null);
        $this->assertArrayHasKey('cost_value', $result['summary'] ?? []);
    }

    public function test_get_cash_position_returns_components(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        /** @var GetCashPositionTool $tool */
        $tool = app(GetCashPositionTool::class);
        $result = $tool->execute($admin, []);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('cash_position', $result['type'] ?? null);
        $this->assertArrayHasKey('accounts_receivable', $result);
        $this->assertArrayHasKey('recent_payment_mix', $result);
    }

    public function test_calculate_scenario_price_increase(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        /** @var CalculateScenarioTool $tool */
        $tool = app(CalculateScenarioTool::class);
        $result = $tool->execute($admin, [
            'scenario_type' => 'price_increase',
            'percent_change' => 5,
            'relative_date' => 'this_month',
        ]);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('scenario_calculation', $result['type'] ?? null);
        $this->assertArrayHasKey('projected', $result);
        $this->assertArrayHasKey('disclaimer', $result);
    }
}
