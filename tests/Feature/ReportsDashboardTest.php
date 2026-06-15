<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ReportsDashboardTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_reports_dashboard_returns_kpis_and_charts(): void
    {
        $response = $this->getJson('/api/v1/reports/dashboard?from_date=2026-06-01&to_date=2026-06-30')
            ->assertOk()
            ->assertJsonStructure([
                'period' => ['from_date', 'to_date', 'previous_from_date', 'previous_to_date'],
                'kpis' => [
                    'total_sales' => ['value', 'change_pct'],
                    'gross_profit' => ['value', 'change_pct'],
                    'receivables' => ['value', 'change_pct'],
                    'inventory_value' => ['value', 'change_pct'],
                ],
                'sales_trend',
                'top_products',
                'sales_by_channel',
            ]);

        $this->assertIsArray($response->json('sales_trend'));
        $this->assertGreaterThan(0, count($response->json('sales_trend')));
    }
}
