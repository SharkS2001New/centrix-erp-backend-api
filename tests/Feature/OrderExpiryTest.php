<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\OrderExpiryService;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OrderExpiryTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function createStaleBookedSale(User $admin, int $orderNum): Sale
    {
        $template = Sale::query()->firstOrFail();

        $sale = Sale::create([
            'order_num' => $orderNum,
            'branch_id' => $admin->branch_id ?? $template->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $template->customer_num,
            'status' => 'booked',
            'total_vat' => 0,
            'order_total' => 500,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        Sale::query()->whereKey($sale->id)->update([
            'created_at' => now()->subDays(6),
            'updated_at' => now()->subDays(6),
        ]);

        return $sale->fresh();
    }

    public function test_expire_stale_orders_command_moves_old_pipeline_orders_to_expired(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $sale = $this->createStaleBookedSale($admin, 96001);

        $exit = Artisan::call('erp:expire-stale-orders', [
            '--organization' => $admin->organization_id,
        ]);

        $this->assertSame(0, $exit);
        $sale->refresh();
        $this->assertSame('expired', $sale->status);
        $this->assertNotNull($sale->expired_at);
        $this->assertSame($admin->id, (int) $sale->expired_by);
    }

    public function test_order_expiry_service_respects_disabled_setting(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales']['order_expiry_enabled'] = false;
        $org->update(['module_settings' => $settings]);

        $sale = $this->createStaleBookedSale($admin, 96002);
        $service = app(OrderExpiryService::class);

        $count = $service->expireStaleOrdersForOrganization($org->fresh());

        $this->assertSame(0, $count);
        $this->assertSame('booked', $sale->fresh()->status);
    }

    public function test_route_orders_list_excludes_cancelled_and_expired_by_default(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $route = \App\Models\RouteModel::query()->firstOrFail();
        $template = Sale::query()->firstOrFail();

        $cancelled = Sale::create([
            'order_num' => 96003,
            'branch_id' => $admin->branch_id ?? $template->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'customer_num' => $template->customer_num,
            'route_id' => $route->id,
            'status' => 'cancelled',
            'total_vat' => 0,
            'order_total' => 400,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'cancelled_at' => now(),
            'cancelled_by' => $admin->id,
        ]);

        $expired = Sale::create([
            'order_num' => 96004,
            'branch_id' => $admin->branch_id ?? $template->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'customer_num' => $template->customer_num,
            'route_id' => $route->id,
            'status' => 'expired',
            'total_vat' => 0,
            'order_total' => 450,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'expired_at' => now(),
            'expired_by' => $admin->id,
        ]);

        $res = $this->getJson('/api/v1/sales?route_orders=1&exclude_statuses=cancelled,expired&per_page=200');
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertNotContains($cancelled->id, $ids);
        $this->assertNotContains($expired->id, $ids);
    }

    public function test_sales_index_filters_expired_status(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $template = Sale::query()->firstOrFail();
        $expired = Sale::create([
            'order_num' => 96005,
            'branch_id' => $admin->branch_id ?? $template->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $template->customer_num,
            'status' => 'expired',
            'total_vat' => 0,
            'order_total' => 300,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'expired_at' => now(),
            'expired_by' => $admin->id,
        ]);

        $res = $this->getJson('/api/v1/sales?filter[status]=expired&per_page=200');
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($expired->id, $ids);
    }
}
