<?php

namespace Tests\Feature;

use App\Models\DispatchTrip;
use App\Models\Organization;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class DispatchTripDeleteTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableDistribution(User $user): void
    {
        $org = Organization::findOrFail($user->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = true;
        $org->update(['enabled_modules' => $modules]);
    }

    public function test_draft_trip_chart_can_be_deleted_and_orders_are_detached(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistribution($admin);
        Sanctum::actingAs($admin);

        $route = RouteModel::query()->firstOrFail();
        $template = Sale::query()->firstOrFail();

        $sale = Sale::query()->create([
            'order_num' => 96101,
            'branch_id' => $admin->branch_id ?? $template->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $template->customer_num,
            'route_id' => $route->id,
            'status' => 'processed',
            'order_total' => 1500,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'archived' => 0,
        ]);

        $trip = DispatchTrip::query()->create([
            'branch_id' => $admin->branch_id ?? $template->branch_id,
            'organization_id' => $admin->organization_id,
            'trip_code' => 'TRIP-DEL-'.uniqid(),
            'route_id' => $route->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        $trip->sales()->attach($sale->id, ['stop_seq' => 1]);

        $this->deleteJson("/api/v1/dispatch-trips/{$trip->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('dispatch_trips', ['id' => $trip->id]);
        $this->assertDatabaseMissing('dispatch_trip_sales', [
            'trip_id' => $trip->id,
            'sale_id' => $sale->id,
        ]);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'processed']);
    }

    public function test_in_transit_trip_must_be_cancelled_before_delete(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistribution($admin);
        Sanctum::actingAs($admin);

        $route = RouteModel::query()->firstOrFail();
        $trip = DispatchTrip::query()->create([
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'trip_code' => 'TRIP-LIVE-'.uniqid(),
            'route_id' => $route->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'in_transit',
            'created_by' => $admin->id,
        ]);

        $this->deleteJson("/api/v1/dispatch-trips/{$trip->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Only draft or cancelled trips can be deleted. Cancel the trip first.']);

        $this->postJson("/api/v1/dispatch-trips/{$trip->id}/cancel")->assertOk();
        $this->deleteJson("/api/v1/dispatch-trips/{$trip->id}")->assertNoContent();
        $this->assertDatabaseMissing('dispatch_trips', ['id' => $trip->id]);
    }
}
