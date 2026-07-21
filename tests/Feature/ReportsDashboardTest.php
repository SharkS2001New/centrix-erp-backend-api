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

    public function test_reports_dashboard_defaults_to_today_when_dates_omitted(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-06-20 12:00:00', 'Africa/Nairobi'));

        $response = $this->getJson('/api/v1/reports/dashboard')
            ->assertOk()
            ->json();

        $this->assertSame('2026-06-20', $response['period']['from_date'] ?? null);
        $this->assertSame('2026-06-20', $response['period']['to_date'] ?? null);

        \Carbon\Carbon::setTestNow();
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

    public function test_reports_dashboard_sales_by_channel_includes_mobile_and_backoffice_labels(): void
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

        $backoffice = $channels->firstWhere('channel', 'backoffice');
        $mobile = $channels->firstWhere('channel', 'mobile');

        $this->assertNotNull($backoffice, 'Expected backoffice channel in sales_by_channel');
        $this->assertSame('Backoffice', $backoffice['channel_label']);
        $this->assertEqualsWithDelta(1000.0, (float) $backoffice['revenue'], 0.01);

        $this->assertNotNull($mobile, 'Expected mobile channel in sales_by_channel');
        $this->assertSame('Mobile', $mobile['channel_label']);
        $this->assertEqualsWithDelta(500.0, (float) $mobile['revenue'], 0.01);
    }

    public function test_reports_dashboard_counts_pending_approval_orders_in_financial_kpis(): void
    {
        $template = Sale::query()->firstOrFail();
        $baseline = $this->getJson('/api/v1/reports/dashboard?from_date=2026-06-01&to_date=2026-06-30')
            ->assertOk()
            ->json('kpis.total_sales.value');

        Sale::create([
            'order_num' => 99003,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'backend',
            'cashier_id' => $template->cashier_id,
            'customer_num' => $template->customer_num,
            'status' => 'pending_approval',
            'total_vat' => 80,
            'order_total' => 800,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'archived' => 0,
            'completed_at' => null,
            'created_at' => '2026-06-16 10:00:00',
        ]);

        $after = $this->getJson('/api/v1/reports/dashboard?from_date=2026-06-01&to_date=2026-06-30')
            ->assertOk()
            ->json('kpis.total_sales.value');

        $this->assertEqualsWithDelta((float) $baseline + 800.0, (float) $after, 0.01);
    }
}
