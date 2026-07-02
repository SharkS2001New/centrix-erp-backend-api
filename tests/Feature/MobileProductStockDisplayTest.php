<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MobileProductStockDisplayTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableSplitShopStoreStock(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'enable_retail_pricing' => true,
            'retail_shop_wholesale_store_stock' => true,
            'allow_sell_from_shop' => true,
            'allow_sell_from_store' => true,
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
            'username' => 'mobile_stock_'.uniqid(),
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
            'client_id' => 'MOBILE_STOCK_'.uniqid(),
            'login_channel' => 'mobile',
        ])->assertOk()->json('token');
    }

    public function test_mobile_product_list_preserves_split_shop_and_store_stock(): void
    {
        $this->enableSplitShopStoreStock();
        $user = $this->makeMobileUser();
        $token = $this->loginMobile($user);
        $product = Product::firstOrFail();

        CurrentStock::query()
            ->where('product_code', $product->product_code)
            ->where('branch_id', $user->branch_id)
            ->update(['shop_quantity' => 12, 'store_quantity' => 71]);

        $payload = $this->withToken($token)
            ->getJson('/api/v1/products', [
                'q' => $product->product_code,
                'per_page' => 5,
                'branch_id' => $user->branch_id,
            ])
            ->assertOk()
            ->json();

        $row = collect($payload['data'] ?? [])
            ->firstWhere('product_code', $product->product_code);

        $this->assertNotNull($row);
        $this->assertTrue($row['sales_stock_split'] ?? false);
        $this->assertEquals(12.0, (float) $row['stock_in_shop']);
        $this->assertEquals(71.0, (float) $row['stock_in_store']);
    }
}
