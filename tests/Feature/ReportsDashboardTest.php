<?php

namespace Tests\Feature;

use App\Models\Sale;
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
                    'inventory_value' => ['value', 'shop_value', 'store_value', 'change_pct'],
                ],
                'sales_trend',
                'top_products',
                'sales_by_channel',
            ]);

        $this->assertIsArray($response->json('sales_trend'));
        $this->assertGreaterThan(0, count($response->json('sales_trend')));
    }

    public function test_reports_dashboard_sales_by_channel_includes_mobile_and_erp_labels(): void
    {
        $template = Sale::query()->firstOrFail();
        $saleDate = '2026-06-15 10:00:00';

        Sale::create([
            'order_num' => 99001,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'backend',
            'cashier_id' => $template->cashier_id,
            'customer_num' => $template->customer_num,
            'status' => 'completed',
            'total_vat' => 100,
            'order_total' => 1000,
            'payment_status' => 'paid',
            'amount_paid' => 1000,
            'archived' => 0,
            'completed_at' => $saleDate,
            'created_at' => $saleDate,
        ]);

        Sale::create([
            'order_num' => 99002,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $template->cashier_id,
            'customer_num' => $template->customer_num,
            'status' => 'paid',
            'total_vat' => 50,
            'order_total' => 500,
            'payment_status' => 'paid',
            'amount_paid' => 500,
            'archived' => 0,
            'completed_at' => null,
            'created_at' => $saleDate,
        ]);

        $channels = collect(
            $this->getJson('/api/v1/reports/dashboard?from_date=2026-06-01&to_date=2026-06-30')
                ->assertOk()
                ->json('sales_by_channel'),
        );

        $erp = $channels->firstWhere('channel', 'erp');
        $mobile = $channels->firstWhere('channel', 'mobile');

        $this->assertNotNull($erp, 'Expected ERP/backend channel in sales_by_channel');
        $this->assertSame('ERP', $erp['channel_label']);
        $this->assertEqualsWithDelta(1000.0, (float) $erp['revenue'], 0.01);

        $this->assertNotNull($mobile, 'Expected mobile channel in sales_by_channel');
        $this->assertSame('Mobile', $mobile['channel_label']);
        $this->assertEqualsWithDelta(500.0, (float) $mobile['revenue'], 0.01);
    }
}
