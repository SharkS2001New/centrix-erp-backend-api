<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DispatchTrip;
use App\Models\Driver;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MobileDriverApiTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_driver_can_list_today_trips_and_stops(): void
    {
        [$user, $driver, $trip, $sale] = $this->makeDriverTripWithStop();
        $token = $this->loginMobile($user);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/driver/trips/today')
            ->assertOk()
            ->assertJsonPath('driver.id', $driver->id)
            ->assertJsonCount(1, 'trips');

        $this->withToken($token)
            ->getJson("/api/v1/mobile/driver/trips/{$trip->id}/stops")
            ->assertOk()
            ->assertJsonCount(1, 'stops')
            ->assertJsonPath('stops.0.sale_id', $sale->id);
    }

    public function test_driver_can_list_upcoming_and_past_trips(): void
    {
        [$user, $driver, $trip, $sale] = $this->makeDriverTripWithStop();
        $token = $this->loginMobile($user);

        $upcoming = DispatchTrip::create([
            'branch_id' => $trip->branch_id,
            'trip_code' => 'TRIP-UP-'.uniqid(),
            'driver_id' => $driver->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'status' => 'draft',
            'created_by' => $trip->created_by,
        ]);

        $past = DispatchTrip::create([
            'branch_id' => $trip->branch_id,
            'trip_code' => 'TRIP-PAST-'.uniqid(),
            'driver_id' => $driver->id,
            'scheduled_date' => now()->subDay()->toDateString(),
            'status' => 'completed',
            'created_by' => $trip->created_by,
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/driver/trips/upcoming')
            ->assertOk()
            ->assertJsonCount(1, 'trips')
            ->assertJsonPath('trips.0.id', $upcoming->id);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/driver/trips/by-date?date='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'trips')
            ->assertJsonPath('trips.0.id', $past->id);
    }

    public function test_driver_can_mark_stop_delivered_with_pod(): void
    {
        [$user, $driver, $trip, $sale] = $this->makeDriverTripWithStop();
        $token = $this->loginMobile($user);

        $this->withToken($token)
            ->post("/api/v1/mobile/driver/stops/{$sale->id}/deliver", [
                'recipient_name' => 'Jane Customer',
                'gps_lat' => -1.2921,
                'gps_lng' => 36.8219,
                'photo' => UploadedFile::fake()->image('delivery.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('stop.status', 'delivered')
            ->assertJsonPath('stop.pod_captured', true);

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'status' => 'delivered',
        ]);
    }

    public function test_driver_cannot_mark_stop_delivered_without_photo(): void
    {
        [$user, $driver, $trip, $sale] = $this->makeDriverTripWithStop();
        $token = $this->loginMobile($user);

        $this->withToken($token)
            ->postJson("/api/v1/mobile/driver/stops/{$sale->id}/deliver", [
                'recipient_name' => 'Jane Customer',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Photo proof of delivery is required before marking this order as delivered.',
            );
    }

    public function test_driver_can_collect_cod_on_deliver(): void
    {
        [$user, $driver, $trip, $sale] = $this->makeDriverTripWithStop();
        $sale->update([
            'order_total' => 100,
            'amount_paid' => 0,
            'payment_status' => 'unpaid',
        ]);

        $token = $this->loginMobile($user);

        $this->withToken($token)
            ->post("/api/v1/mobile/driver/stops/{$sale->id}/deliver", [
                'recipient_name' => 'Jane Customer',
                'collect_amount' => 100,
                'payment_method_code' => 'CASH',
                'photo' => UploadedFile::fake()->image('delivery.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('stop.status', 'delivered');

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'amount_paid' => 100,
            'payment_status' => 'paid',
        ]);
    }

    public function test_carry_over_trip_with_processed_stop_appears_on_today_and_can_deliver(): void
    {
        [$user, $driver, $trip, $sale] = $this->makeDriverTripWithStop();
        $trip->update([
            'scheduled_date' => now()->subDay()->toDateString(),
            'status' => 'completed',
        ]);
        $token = $this->loginMobile($user);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/driver/trips/today')
            ->assertOk()
            ->assertJsonPath('trips.0.id', $trip->id)
            ->assertJsonPath('trips.0.is_carry_over', true)
            ->assertJsonPath('trips.0.pending_stops_count', 1);

        $this->withToken($token)
            ->getJson("/api/v1/mobile/driver/trips/{$trip->id}/stops")
            ->assertOk()
            ->assertJsonPath('stops.0.is_deliverable', true);

        $this->withToken($token)
            ->post("/api/v1/mobile/driver/stops/{$sale->id}/deliver", [
                'recipient_name' => 'Jane Customer',
                'photo' => UploadedFile::fake()->image('delivery.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('stop.status', 'delivered');
    }

    public function test_driver_attendance_session_when_enabled(): void
    {
        [$user, $driver, $trip, $sale] = $this->makeDriverTripWithStop();
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = $admin->organization;
        $settings = $org->module_settings ?? [];
        $settings['distribution'] = array_merge($settings['distribution'] ?? [], [
            'mobile_enable_driver_attendance' => true,
        ]);
        $org->update(['module_settings' => $settings]);

        $token = $this->loginMobile($user);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/driver/attendance/session')
            ->assertOk()
            ->assertJsonPath('feature_enabled', true);
    }

    /** @return array{0: User, 1: Driver, 2: DispatchTrip, 3: Sale} */
    protected function makeDriverTripWithStop(): array
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = $admin->organization;
        $settings = $org->module_settings ?? [];
        $settings['distribution'] = array_merge($settings['distribution'] ?? [], [
            'mobile_enable_driver_app' => true,
            'require_pod_on_delivered' => false,
        ]);
        $org->update(['module_settings' => $settings]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'driver_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Test Driver',
            'is_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['mobile'],
            'is_active' => true,
        ]);

        $driver = Driver::create([
            'branch_id' => $admin->branch_id,
            'user_id' => $user->id,
            'driver_code' => 'DRV-'.uniqid(),
            'full_name' => 'Test Driver',
            'is_active' => true,
        ]);

        $customer = Customer::firstOrFail();
        $customer->update([
            'latitude' => -1.2921,
            'longitude' => 36.8219,
        ]);

        $product = Product::firstOrFail();
        $sale = Sale::create([
            'order_num' => random_int(90000, 99999),
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'order_source' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'status' => 'processed',
            'total_vat' => 0,
            'order_total' => 100,
            'amount_paid' => 100,
            'payment_status' => 'paid',
            'fulfillment_meta' => [
                'driver_id' => $driver->id,
            ],
        ]);

        $sale->items()->create([
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'quantity' => 1,
            'unit_price' => 100,
            'amount' => 100,
            'product_vat' => 0,
        ]);

        $trip = DispatchTrip::create([
            'branch_id' => $admin->branch_id,
            'trip_code' => 'TRIP-'.uniqid(),
            'driver_id' => $driver->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'in_transit',
            'created_by' => $admin->id,
        ]);

        $trip->sales()->attach($sale->id, ['stop_seq' => 1]);
        $sale->update([
            'fulfillment_meta' => array_merge($sale->fulfillment_meta ?? [], [
                'trip_id' => $trip->id,
                'driver_id' => $driver->id,
            ]),
        ]);

        return [$user, $driver, $trip, $sale];
    }

    protected function loginMobile(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'MOBILE_TEST_'.uniqid(),
            'login_channel' => 'mobile',
        ])->assertOk()->json('token');
    }
}
