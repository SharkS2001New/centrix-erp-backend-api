<?php

namespace Tests\Feature;

use App\Models\DispatchTrip;
use App\Models\Driver;
use App\Models\Expense;
use App\Models\ExpenseGroup;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class TripLoadReportsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableDistributionModules(User $user): void
    {
        $org = Organization::findOrFail($user->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = true;
        $org->update(['enabled_modules' => $modules]);
        // Bypass org licence middleware in this isolated feature suite.
        $user->forceFill(['is_super_admin' => true])->save();
    }

    public function test_vehicle_trip_loads_includes_weight_cogs_and_expenses(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $route = RouteModel::query()->firstOrFail();
        $product = Product::query()->firstOrFail();
        $product->update([
            'last_cost_price' => 300,
            'product_weight' => 2.5,
        ]);

        $driver = Driver::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'driver_code' => 'DRV-LOAD-1',
            'full_name' => 'Load Report Driver',
            'is_active' => true,
        ]);
        $vehicle = Vehicle::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'vehicle_code' => 'V-LOAD-1',
            'vehicle_name' => 'Load Report Van',
            'plate_number' => 'KAA-LOAD-1',
            'max_weight_kg' => 1000,
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'route_id' => $route->id,
            'channel' => 'mobile',
            'status' => 'processed',
            'order_num' => 91001,
            'order_total' => 1000,
            'total_vat' => 100,
            'amount_paid' => 0,
            'cashier_id' => $admin->id,
        ]);
        $sale->items()->create([
            'product_code' => $product->product_code,
            'line_no' => 1,
            'quantity' => 2,
            'selling_price' => 500,
            'amount' => 1000,
        ]);

        $trip = DispatchTrip::create([
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'trip_code' => 'TRIP-LOAD-001',
            'route_id' => $route->id,
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        $trip->sales()->attach([$sale->id => ['stop_seq' => 1]]);

        $group = ExpenseGroup::query()->firstOrFail();
        $paymentMethod = PaymentMethod::query()->firstOrFail();
        Expense::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'expense_group_id' => $group->id,
            'dispatch_trip_id' => $trip->id,
            'description' => 'Fuel',
            'expense_amount' => 50,
            'expense_date' => now()->toDateString(),
            'payment_method_id' => $paymentMethod->id,
            'recorded_by' => $admin->id,
        ]);

        $vehicleRes = $this->getJson('/api/v1/reports/vehicle-trip-loads?vehicle_id='.$vehicle->id)
            ->assertOk()
            ->assertJsonPath('data.0.trip_code', 'TRIP-LOAD-001')
            ->assertJsonPath('data.0.order_count', 1)
            ->assertJsonPath('data.0.total_weight_kg', 5)
            ->assertJsonPath('data.0.max_weight_kg', 1000)
            ->assertJsonPath('data.0.total_cogs', 600)
            ->assertJsonPath('data.0.total_profit', 300)
            ->assertJsonPath('data.0.total_expenses', 50)
            ->assertJsonPath('data.0.net_profit', 250);

        $this->assertSame(1, (int) data_get($vehicleRes->json(), 'summary.trip_count'));

        $this->getJson('/api/v1/reports/driver-trip-loads?driver_id='.$driver->id)
            ->assertOk()
            ->assertJsonPath('data.0.trip_code', 'TRIP-LOAD-001')
            ->assertJsonPath('data.0.driver_name', 'Load Report Driver')
            ->assertJsonPath('data.0.total_cogs', 600)
            ->assertJsonPath('data.0.net_profit', 250);
    }

    public function test_mobile_catalog_includes_trip_load_reports(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $payload = $this->getJson('/api/v1/manager/reports/catalog')->assertOk()->json();
        $keys = [];
        foreach ($payload['featured'] ?? [] as $item) {
            if (is_array($item) && ! empty($item['key'])) {
                $keys[] = (string) $item['key'];
            }
        }
        foreach ($payload['categories'] ?? [] as $category) {
            foreach ($category['reports'] ?? [] as $item) {
                if (is_array($item) && ! empty($item['key'])) {
                    $keys[] = (string) $item['key'];
                }
            }
        }

        $this->assertContains('vehicle-trip-loads', $keys);
        $this->assertContains('driver-trip-loads', $keys);
        $this->assertContains('profit-loss-by-product', $keys);
    }
}
