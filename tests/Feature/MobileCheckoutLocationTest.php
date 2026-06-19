<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MobileCheckoutLocationTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableLocationVerification(
        bool $allowOffline = false,
        float $radiusMetres = 5,
    ): void {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'mobile_enable_checkout_location_verification' => true,
            'mobile_allow_offline_orders' => $allowOffline,
            'mobile_checkout_location_radius_metres' => $radiusMetres,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    protected function makeMobileUser(): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        return User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'mobile_loc_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'Mobile Rep',
            'is_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['mobile'],
            'is_active' => true,
        ]);
    }

    protected function loginMobile(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'MOBILE_LOC_'.uniqid(),
            'login_channel' => 'mobile',
        ])->assertOk()->json('token');
    }

    protected function customerWithLocation(): Customer
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $max = (int) Customer::query()->max('customer_num');

        return Customer::create([
            'customer_num' => $max + 1,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'customer_name' => 'Located Customer '.uniqid(),
            'customer_type' => 'regular',
            'phone_number' => '07'.random_int(10000000, 99999999),
            'latitude' => -1.292100,
            'longitude' => 36.821900,
            'created_by' => $admin->id,
        ]);
    }

    protected function mobileCartWithLine(string $token, User $user): array
    {
        $product = Product::firstOrFail();

        $cart = $this->withToken($token)
            ->postJson('/api/v1/sales/carts', [
                'channel' => 'mobile',
                'branch_id' => $user->branch_id,
            ])
            ->assertCreated()
            ->json();

        $this->withToken($token)
            ->postJson("/api/v1/sales/carts/{$cart['id']}/lines", [
                'product_code' => $product->product_code,
                'quantity' => 1,
                'unit_price' => 100,
                'on_wholesale_retail' => 0,
            ])
            ->assertCreated();

        return $cart;
    }

    public function test_checkout_within_radius_records_location_on_sale(): void
    {
        $this->enableLocationVerification();
        $user = $this->makeMobileUser();
        $customer = $this->customerWithLocation();
        $token = $this->loginMobile($user);
        $cart = $this->mobileCartWithLine($token, $user);

        $sale = $this->withToken($token)
            ->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
                'customer_num' => $customer->customer_num,
                'payment_method_code' => 'CASH',
                'pay_now' => 100,
                'checkout_latitude' => -1.292100,
                'checkout_longitude' => 36.821910,
            ])
            ->assertCreated()
            ->json();

        $this->assertTrue($sale['fulfillment_meta']['location_check']['verified'] ?? false);
        $this->assertFalse($sale['fulfillment_meta']['location_check']['offline_order'] ?? true);
    }

    public function test_checkout_outside_radius_is_rejected(): void
    {
        $this->enableLocationVerification();
        $user = $this->makeMobileUser();
        $customer = $this->customerWithLocation();
        $token = $this->loginMobile($user);
        $cart = $this->mobileCartWithLine($token, $user);

        $this->withToken($token)
            ->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
                'customer_num' => $customer->customer_num,
                'payment_method_code' => 'CASH',
                'pay_now' => 100,
                'checkout_latitude' => -1.300000,
                'checkout_longitude' => 36.821900,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Customer location must be within 5 metres radius.');
    }

    public function test_offline_checkout_allowed_when_setting_enabled(): void
    {
        $this->enableLocationVerification(allowOffline: true);
        $user = $this->makeMobileUser();
        $customer = $this->customerWithLocation();
        $token = $this->loginMobile($user);
        $cart = $this->mobileCartWithLine($token, $user);

        $sale = $this->withToken($token)
            ->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
                'customer_num' => $customer->customer_num,
                'payment_method_code' => 'CASH',
                'pay_now' => 100,
                'offline_order' => true,
            ])
            ->assertCreated()
            ->json();

        $this->assertTrue($sale['fulfillment_meta']['location_check']['offline_order'] ?? false);
        $this->assertFalse($sale['fulfillment_meta']['location_check']['verified'] ?? true);
    }

    public function test_org_admin_can_update_mobile_location_settings(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/erp/settings/sales', [
            'mobile_enable_checkout_location_verification' => true,
            'mobile_allow_offline_orders' => true,
            'mobile_checkout_location_radius_metres' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('sales.mobile_enable_checkout_location_verification', true)
            ->assertJsonPath('sales.mobile_allow_offline_orders', true)
            ->assertJsonPath('sales.mobile_checkout_location_radius_metres', 10);
    }
}
