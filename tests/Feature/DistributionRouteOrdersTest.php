<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class DistributionRouteOrdersTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableDistributionModules(User $user): void
    {
        $org = Organization::findOrFail($user->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = true;
        $modules['distribution.reports'] = true;
        $org->update(['enabled_modules' => $modules]);
    }

    public function test_sales_index_route_orders_filter_includes_backoffice_route_orders_by_default(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $mobileRoute = Sale::query()->where('channel', 'mobile')->whereNotNull('route_id')->first();
        $this->assertNotNull($mobileRoute, 'Demo seed should include a mobile route order');

        $nonRoute = Sale::query()->whereNull('route_id')->first();
        $this->assertNotNull($nonRoute, 'Demo seed should include a non-route order');

        $route = \App\Models\RouteModel::query()->firstOrFail();
        $template = Sale::query()->firstOrFail();

        $backendRouteOrder = Sale::create([
            'order_num' => 95001,
            'branch_id' => $admin->branch_id ?? $template->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $template->customer_num,
            'route_id' => $route->id,
            'status' => 'processed',
            'total_vat' => 100,
            'order_total' => 1200,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        $res = $this->getJson('/api/v1/sales?route_orders=1&per_page=200');
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($mobileRoute->id, $ids);
        $this->assertContains($backendRouteOrder->id, $ids);
        $this->assertNotContains($nonRoute->id, $ids);

        foreach ($res->json('data') as $row) {
            $this->assertNotNull($row['route_id']);
            $this->assertContains($row['channel'], ['mobile', 'pos', 'backend', 'backoffice']);
        }
    }

    public function test_sales_index_route_orders_with_date_range_does_not_error(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $from = now()->subDays(30)->toDateString();
        $to = now()->toDateString();

        $res = $this->getJson("/api/v1/sales?route_orders=1&from_date={$from}&to_date={$to}&per_page=25");
        $res->assertOk();
    }

    public function test_dispatch_orders_with_route_id_does_not_duplicate_customer_join(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $route = \App\Models\RouteModel::query()->firstOrFail();

        $res = $this->getJson("/api/v1/sales?dispatch_orders=1&route_id={$route->id}&per_page=200");
        $res->assertOk();
    }

    public function test_dispatch_orders_filter_includes_backend_route_orders_by_default(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $route = \App\Models\RouteModel::query()->firstOrFail();
        $template = Sale::query()->firstOrFail();

        $backendRouteOrder = Sale::create([
            'order_num' => 95011,
            'branch_id' => $admin->branch_id ?? $template->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $template->customer_num,
            'route_id' => $route->id,
            'status' => 'processed',
            'total_vat' => 100,
            'order_total' => 1200,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        $res = $this->getJson('/api/v1/sales?dispatch_orders=1&per_page=200');
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($backendRouteOrder->id, $ids);
    }

    public function test_loading_list_excludes_backend_route_orders_when_setting_disabled(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $route = \App\Models\RouteModel::query()->firstOrFail();
        $product = \App\Models\Product::query()->firstOrFail();
        $template = Sale::query()->firstOrFail();
        $branchId = $admin->branch_id ?? $template->branch_id;

        $createRouteSale = function (int $orderNum, string $channel, float $amount) use ($admin, $branchId, $route, $template, $product) {
            $sale = Sale::create([
                'order_num' => $orderNum,
                'branch_id' => $branchId,
                'organization_id' => $admin->organization_id,
                'channel' => $channel,
                'cashier_id' => $admin->id,
                'customer_num' => $template->customer_num,
                'route_id' => $route->id,
                'status' => 'processed',
                'total_vat' => 0,
                'order_total' => $amount,
                'payment_status' => 'unpaid',
                'amount_paid' => 0,
            ]);

            \App\Models\SaleItem::create([
                'sale_id' => $sale->id,
                'product_code' => $product->product_code,
                'line_no' => 1,
                'quantity' => 1,
                'selling_price' => $amount,
                'amount' => $amount,
            ]);

            return $sale;
        };

        $mobileSale = $createRouteSale(95002, 'mobile', 500);
        $backendSale = $createRouteSale(95003, 'backend', 900);

        $trip = \App\Models\DispatchTrip::create([
            'branch_id' => $branchId,
            'trip_code' => 'TRIP-TEST-001',
            'route_id' => $route->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        $trip->sales()->attach([$mobileSale->id => ['stop_seq' => 1], $backendSale->id => ['stop_seq' => 2]]);

        $loadingList = app(\App\Services\Fulfillment\LoadingListBuilder::class)->syncLoadingList($trip->fresh());
        $this->assertSame(1400.0, (float) $loadingList->total_amount);

        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['distribution'] = array_merge($settings['distribution'] ?? [], [
            'include_normal_orders_in_loading_list' => false,
        ]);
        $org->update(['module_settings' => $settings]);

        $loadingList = app(\App\Services\Fulfillment\LoadingListBuilder::class)->syncLoadingList($trip->fresh());
        $this->assertSame(500.0, (float) $loadingList->total_amount);
    }

    public function test_trip_reconciliation_endpoint_returns_checklist(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $trip = \App\Models\DispatchTrip::query()->first();
        if (! $trip) {
            $this->markTestSkipped('No dispatch trips in demo seed.');
        }

        $res = $this->getJson("/api/v1/dispatch-trips/{$trip->id}/reconciliation");
        $res->assertOk();
        $res->assertJsonStructure([
            'trip' => ['id', 'trip_code', 'status'],
            'loading_list',
            'delivery',
            'cash',
            'orders',
            'steps',
            'blockers',
            'can_complete',
        ]);
    }
}
