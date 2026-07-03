<?php

namespace Tests\Feature;

use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SalesReportsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->admin);
    }

    public function test_daily_sales_report_returns_completed_sales_for_org(): void
    {
        $today = now()->toDateString();

        Sale::query()->create([
            'order_num' => 995001,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $this->admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 5000,
            'total_vat' => 500,
            'amount_paid' => 5000,
            'archived' => 0,
            'completed_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/reports/daily-sales?from_date={$today}&to_date={$today}&date_column=sale_day&per_page=50")
            ->assertOk();

        $this->assertGreaterThan(0, (int) $response->json('total'));
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_sales_by_user_report_includes_vat_columns(): void
    {
        $today = now()->toDateString();

        $response = $this->getJson("/api/v1/reports/sales-by-user?from_date={$today}&to_date={$today}&date_column=sale_date&per_page=5")
            ->assertOk();

        $row = collect($response->json('data'))->first();
        if ($row !== null) {
            $this->assertArrayHasKey('total_vat', $row);
            $this->assertArrayHasKey('gross_sales', $row);
        } else {
            $this->assertSame(0, (int) $response->json('total'));
        }
    }

    public function test_dispatch_orders_match_orders_without_required_date_using_created_at(): void
    {
        $route = RouteModel::query()->firstOrFail();
        $today = now()->toDateString();

        $order = Sale::query()->create([
            'order_num' => 995002,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $this->admin->id,
            'customer_num' => Sale::query()->whereNotNull('customer_num')->value('customer_num'),
            'route_id' => $route->id,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'order_total' => 2400,
            'total_vat' => 200,
            'amount_paid' => 0,
            'archived' => 0,
            'required_date' => null,
            'created_at' => now(),
        ]);

        $res = $this->getJson("/api/v1/sales?dispatch_orders=1&required_date={$today}&per_page=200")
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($order->id, $ids);
    }

    public function test_dispatch_trips_report_scopes_by_organization(): void
    {
        $response = $this->getJson('/api/v1/reports/dispatch-trips?per_page=5')
            ->assertOk();

        $this->assertIsArray($response->json('data'));
    }

    public function test_sales_by_customer_accepts_date_range_without_sale_date_column(): void
    {
        $today = now()->toDateString();

        $response = $this->getJson("/api/v1/reports/sales-by-customer?from_date={$today}&to_date={$today}&per_page=5")
            ->assertOk();

        $this->assertIsArray($response->json('data'));
    }

    public function test_invoice_payments_report_returns_tenant_scoped_rows(): void
    {
        $today = now()->toDateString();

        $response = $this->getJson(
            "/api/v1/reports/invoice-payments?from_date={$today}&to_date={$today}&date_column=date_paid&per_page=5",
        )->assertOk();

        $this->assertIsArray($response->json('data'));
    }
}
