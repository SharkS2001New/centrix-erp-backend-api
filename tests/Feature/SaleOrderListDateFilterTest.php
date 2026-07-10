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

    public function test_sales_list_filters_by_payment_status_independent_of_workflow_status(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $processedUnpaid = Sale::query()->create([
            'order_num' => 993003,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'is_credit_sale' => true,
            'order_total' => 800,
            'amount_paid' => 0,
        ]);

        $processedPaid = Sale::query()->create([
            'order_num' => 993004,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'processed',
            'payment_status' => 'paid',
            'order_total' => 900,
            'amount_paid' => 900,
        ]);

        $response = $this->getJson('/api/v1/sales?filter[payment_status]=unpaid&per_page=200');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($processedUnpaid->id));
        $this->assertFalse($ids->contains($processedPaid->id));
    }

    public function test_payment_status_unpaid_queue_excludes_completed_and_fully_paid_orders(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $completedUnpaidLabel = Sale::query()->create([
            'order_num' => 993005,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'order_total' => 500,
            'amount_paid' => 500,
        ]);

        $deliveredUnpaid = Sale::query()->create([
            'order_num' => 993006,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'delivered',
            'payment_status' => 'unpaid',
            'order_total' => 1200,
            'amount_paid' => 0,
        ]);

        $response = $this->getJson('/api/v1/sales?filter[payment_status]=unpaid&per_page=200');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($completedUnpaidLabel->id));
        $this->assertTrue($ids->contains($deliveredUnpaid->id));
    }

    public function test_sales_list_orders_newest_first_by_date_by_default(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $older = Sale::query()->create([
            'order_num' => 993101,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'booked',
            'payment_status' => 'unpaid',
            'order_total' => 100,
            'amount_paid' => 0,
            'completed_at' => null,
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

        $newer = Sale::query()->create([
            'order_num' => 993100,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'status' => 'booked',
            'payment_status' => 'unpaid',
            'order_total' => 200,
            'amount_paid' => 0,
            'completed_at' => null,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();
        $response = $this->getJson("/api/v1/sales?from_date={$from}&to_date={$to}&per_page=200&sort=-created_at");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $newerPos = array_search((int) $newer->id, $ids, true);
        $olderPos = array_search((int) $older->id, $ids, true);

        $this->assertNotFalse($newerPos);
        $this->assertNotFalse($olderPos);
        $this->assertLessThan($olderPos, $newerPos, 'Newer orders must appear before older ones.');
    }
}
