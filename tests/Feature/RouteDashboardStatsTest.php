<?php

namespace Tests\Feature;

use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class RouteDashboardStatsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_routes_index_include_stats_returns_today_orders_and_sales_per_route(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $route = RouteModel::query()->firstOrFail();
        $template = Sale::query()->firstOrFail();

        Sale::create([
            'order_num' => 96001,
            'branch_id' => $admin->branch_id ?? $template->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'customer_num' => $template->customer_num,
            'route_id' => $route->id,
            'status' => 'processed',
            'total_vat' => 100,
            'order_total' => 1500,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'created_at' => now(),
            'completed_at' => null,
        ]);

        $res = $this->getJson('/api/v1/routes?include_stats=1&stats_period=day&per_page=200');
        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', $route->id);
        $this->assertNotNull($row);
        $this->assertGreaterThanOrEqual(1, (int) ($row['orders_count'] ?? 0));
        $this->assertGreaterThanOrEqual(1500.0, (float) ($row['sales_total'] ?? 0));
    }

    public function test_routes_stats_include_orders_assigned_via_customer_route_only(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $route = RouteModel::query()->firstOrFail();
        $template = Sale::query()->whereNotNull('customer_num')->firstOrFail();
        $customer = \App\Models\Customer::query()->where('customer_num', $template->customer_num)->firstOrFail();
        $customer->update(['route_id' => $route->id]);

        Sale::create([
            'order_num' => 96002,
            'branch_id' => $admin->branch_id ?? $template->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'route_id' => null,
            'status' => 'paid',
            'total_vat' => 0,
            'order_total' => 800,
            'payment_status' => 'paid',
            'amount_paid' => 800,
            'created_at' => now(),
        ]);

        $res = $this->getJson('/api/v1/routes?include_stats=1&stats_period=day&per_page=200');
        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', $route->id);
        $this->assertNotNull($row);
        $this->assertGreaterThanOrEqual(1, (int) ($row['orders_count'] ?? 0));
        $this->assertGreaterThanOrEqual(800.0, (float) ($row['sales_total'] ?? 0));
    }
}
