<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MobileProductStockDisplayTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::where('username', 'admin')->first();
        if ($admin?->organization_id) {
            PlatformSubscription::query()->firstOrCreate(
                ['organization_id' => $admin->organization_id],
                [
                    'status' => 'active',
                    'current_period_start' => now()->subMonth()->toDateString(),
                    'current_period_end' => now()->addYear()->toDateString(),
                    'renewal_price' => 0,
                    'amount' => 0,
                    'currency' => 'KES',
                ],
            );
        }
    }

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

    protected function setMobileProductListMode(string $mode): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'mobile_product_list_mode' => $mode,
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

    public function test_mobile_product_list_excludes_out_of_stock_products(): void
    {
        $user = $this->makeMobileUser();
        $token = $this->loginMobile($user);
        $inStock = Product::firstOrFail();
        $outOfStock = Product::query()
            ->where('product_code', '!=', $inStock->product_code)
            ->firstOrFail();

        CurrentStock::query()
            ->where('product_code', $inStock->product_code)
            ->where('branch_id', $user->branch_id)
            ->update(['shop_quantity' => 5, 'store_quantity' => 10]);

        CurrentStock::query()
            ->where('product_code', $outOfStock->product_code)
            ->where('branch_id', $user->branch_id)
            ->update(['shop_quantity' => 0, 'store_quantity' => 0]);

        $codes = collect(
            $this->withToken($token)
                ->getJson('/api/v1/products', ['per_page' => 200, 'branch_id' => $user->branch_id])
                ->assertOk()
                ->json('data') ?? [],
        )->pluck('product_code')->all();

        $this->assertContains($inStock->product_code, $codes);
        $this->assertNotContains($outOfStock->product_code, $codes);
    }

    public function test_mobile_product_list_includes_out_of_stock_when_configured(): void
    {
        $this->setMobileProductListMode('all_products');
        $user = $this->makeMobileUser();
        $token = $this->loginMobile($user);
        $inStock = Product::firstOrFail();
        $outOfStock = Product::query()
            ->where('product_code', '!=', $inStock->product_code)
            ->firstOrFail();

        CurrentStock::query()
            ->where('product_code', $inStock->product_code)
            ->where('branch_id', $user->branch_id)
            ->update(['shop_quantity' => 5, 'store_quantity' => 10]);

        CurrentStock::query()
            ->where('product_code', $outOfStock->product_code)
            ->where('branch_id', $user->branch_id)
            ->update(['shop_quantity' => 0, 'store_quantity' => 0]);

        $codes = collect(
            $this->withToken($token)
                ->getJson('/api/v1/products', ['per_page' => 200, 'branch_id' => $user->branch_id])
                ->assertOk()
                ->json('data') ?? [],
        )->pluck('product_code')->all();

        $this->assertContains($inStock->product_code, $codes);
        $this->assertContains($outOfStock->product_code, $codes);
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
        $this->assertEquals(12.0, (float) $row['stock_available_shop']);
        $this->assertEquals(71.0, (float) $row['stock_available_store']);
    }

    public function test_mobile_product_list_includes_wholesale_retail_product_with_store_only_stock(): void
    {
        $this->enableSplitShopStoreStock();
        $user = $this->makeMobileUser();
        $token = $this->loginMobile($user);

        $product = Product::query()->firstOrFail();
        $product->update(['sell_on_retail' => true]);

        CurrentStock::query()
            ->where('product_code', $product->product_code)
            ->where('branch_id', $user->branch_id)
            ->update(['shop_quantity' => 0, 'store_quantity' => 106]);

        $codes = collect(
            $this->withToken($token)
                ->getJson('/api/v1/products', [
                    'q' => $product->product_code,
                    'per_page' => 5,
                    'branch_id' => $user->branch_id,
                ])
                ->assertOk()
                ->json('data') ?? [],
        )->pluck('product_code')->all();

        $this->assertContains($product->product_code, $codes);
    }
}
