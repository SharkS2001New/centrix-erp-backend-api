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

class AutoTripAssignmentTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableDistribution(User $user): void
    {
        $org = Organization::findOrFail($user->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = true;
        $settings = $org->module_settings ?? [];
        $settings['distribution'] = array_merge($settings['distribution'] ?? [], [
            'auto_create_trips' => true,
            'assign_on_status' => 'processed',
        ]);
        $org->update([
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ]);
    }

    public function test_order_transition_to_processed_auto_assigns_dispatch_trip(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $this->enableDistribution($user);
        Sanctum::actingAs($user);

        $route = RouteModel::query()->firstOrFail();
        $template = Sale::query()->where('channel', 'mobile')->firstOrFail();

        $sale = Sale::create([
            'order_num' => 96001,
            'branch_id' => $user->branch_id ?? $template->branch_id,
            'organization_id' => $user->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $user->id,
            'customer_num' => $template->customer_num,
            'route_id' => $route->id,
            'status' => 'paid',
            'total_vat' => 100,
            'order_total' => 1200,
            'payment_status' => 'paid',
            'amount_paid' => 1200,
        ]);

        $this->postJson("/api/v1/sales/orders/{$sale->id}/transition", [
            'status' => 'processed',
        ])->assertOk();

        $sale->refresh();
        $tripId = (int) (($sale->fulfillment_meta ?? [])['trip_id'] ?? 0);
        $this->assertGreaterThan(0, $tripId);

        $trip = DispatchTrip::findOrFail($tripId);
        $this->assertSame((int) $route->id, (int) $trip->route_id);
        $this->assertTrue($trip->sales()->where('sales.id', $sale->id)->exists());
    }
}
