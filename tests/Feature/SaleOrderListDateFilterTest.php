<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SaleOrderListDateFilterTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_sales_list_includes_orders_without_completed_at_when_filtering_by_today(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $today = now()->toDateString();

        $sale = Sale::query()->create([
            'order_num' => 993001,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'paid',
            'payment_status' => 'paid',
            'order_total' => 250,
            'amount_paid' => 250,
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/sales?from_date={$today}&to_date={$today}&per_page=200");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($sale->id), 'Paid order without completed_at should appear in today\'s list.');
    }

    public function test_sales_list_excludes_orders_outside_date_range(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $sale = Sale::query()->create([
            'order_num' => 993002,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'order_total' => 100,
            'amount_paid' => 0,
            'completed_at' => null,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $response = $this->getJson("/api/v1/sales?from_date={$yesterday}&to_date={$today}&per_page=200");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($sale->id));
    }
}
