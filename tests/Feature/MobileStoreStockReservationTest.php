<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Organization;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MobileStoreStockReservationTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableStoreOnlySales(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'allow_sell_from_shop' => false,
            'allow_sell_from_store' => true,
            'retail_shop_wholesale_store_stock' => false,
        ]);
        $settings['inventory'] = array_merge($settings['inventory'] ?? [], [
            'default_distribution_sale_location' => 'store',
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
            'username' => 'mobile_store_'.uniqid(),
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
            'client_id' => 'MOBILE_STORE_'.uniqid(),
            'login_channel' => 'mobile',
        ])->assertOk()->json('token');
    }

    public function test_mobile_cart_reserves_store_stock_when_store_only_is_enabled(): void
    {
        $this->enableStoreOnlySales();
        $user = $this->makeMobileUser();
        $token = $this->loginMobile($user);
        $product = Product::firstOrFail();

        CurrentStock::query()
            ->where('product_code', $product->product_code)
            ->where('branch_id', $user->branch_id)
            ->update(['shop_quantity' => 0, 'store_quantity' => 25]);

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
                'quantity' => 4,
                'on_wholesale_retail' => 0,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('stock_reservations', [
            'cart_id' => $cart['id'],
            'product_code' => $product->product_code,
            'stock_location' => 'store',
            'quantity' => 4,
            'released_at' => null,
        ]);

        $this->assertEquals(
            0,
            StockReservation::query()
                ->where('cart_id', $cart['id'])
                ->where('stock_location', 'shop')
                ->whereNull('released_at')
                ->count(),
        );
    }

    public function test_mobile_cart_reserves_store_for_wholesale_only_product_when_split_stock_enabled(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'retail_shop_wholesale_store_stock' => true,
            'allow_sell_from_shop' => true,
            'allow_sell_from_store' => true,
        ]);
        $org->update(['module_settings' => $settings]);

        $user = $this->makeMobileUser();
        $token = $this->loginMobile($user);
        $product = Product::firstOrFail();
        $product->update(['sell_on_retail' => false]);

        CurrentStock::query()
            ->where('product_code', $product->product_code)
            ->where('branch_id', $user->branch_id)
            ->update(['shop_quantity' => 0, 'store_quantity' => 71]);

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
                'quantity' => 4,
                'on_wholesale_retail' => 1,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('stock_reservations', [
            'cart_id' => $cart['id'],
            'product_code' => $product->product_code,
            'stock_location' => 'store',
            'quantity' => 4,
            'released_at' => null,
        ]);
    }
}
