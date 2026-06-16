<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OrderWorkflowTransitionTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_assign_driver_on_already_processed_order_updates_fulfillment_meta(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $driver = Driver::query()->firstOrFail();

        $sale = Sale::query()->create([
            'order_num' => 992001,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'status' => 'processed',
            'payment_status' => 'paid',
            'order_total' => 500,
            'amount_paid' => 500,
        ]);

        $response = $this->postJson("/api/v1/sales/orders/{$sale->id}/transition", [
            'status' => 'processed',
            'fulfillment_meta' => [
                'driver_id' => $driver->id,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('fulfillment_meta.driver_id', $driver->id);
    }

    public function test_same_status_without_fulfillment_meta_returns_friendly_message(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $sale = Sale::query()->create([
            'order_num' => 992002,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'status' => 'processed',
            'payment_status' => 'paid',
            'order_total' => 500,
            'amount_paid' => 500,
        ]);

        $this->postJson("/api/v1/sales/orders/{$sale->id}/transition", [
            'status' => 'processed',
        ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Order is already marked as processed.']);
    }
}
