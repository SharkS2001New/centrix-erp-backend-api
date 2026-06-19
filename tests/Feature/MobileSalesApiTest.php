<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MobileSalesApiTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_mobile_session_can_create_cart_and_add_line(): void
    {
        $user = $this->makeMobileUser();
        $product = \App\Models\Product::firstOrFail();
        $token = $this->loginMobile($user);

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

        $this->withToken($token)
            ->getJson("/api/v1/sales/carts/{$cart['id']}")
            ->assertOk()
            ->assertJsonPath('channel', 'mobile')
            ->assertJsonCount(1, 'lines');
    }

    public function test_mobile_session_can_access_dashboard_orders_and_catalogue_helpers(): void
    {
        $user = $this->makeMobileUser();

        $token = $this->loginMobile($user);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'summary' => ['NoofOrders', 'vatTotals', 'orderTotals', 'noofPaidOrders', 'noofCustomers'],
                'recent_orders',
                'weekly_sales',
                'monthly_sales',
            ]);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/orders')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);

        $this->withToken($token)
            ->getJson('/api/v1/branches')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/uoms?per_page=10')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/vats?per_page=10')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/sales/customers/lookup?q=demo&per_page=10')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_mobile_dashboard_scopes_orders_to_signed_in_rep(): void
    {
        $rep = $this->makeMobileUser(['username' => 'mobile_rep_'.uniqid()]);
        $otherRep = $this->makeMobileUser(['username' => 'mobile_other_'.uniqid()]);

        $template = Sale::query()
            ->where('channel', 'mobile')
            ->firstOrFail();

        Sale::create([
            'order_num' => 91001,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $rep->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'paid',
            'total_vat' => 100,
            'order_total' => 1000,
            'payment_status' => 'paid',
            'amount_paid' => 1000,
        ]);

        Sale::create([
            'order_num' => 91002,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $otherRep->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'paid',
            'total_vat' => 50,
            'order_total' => 500,
            'payment_status' => 'paid',
            'amount_paid' => 500,
        ]);

        $token = $this->loginMobile($rep);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.NoofOrders', 1)
            ->assertJsonPath('summary.orderTotals', 1000);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_no', 91001);
    }

    public function test_mobile_orders_can_include_all_channels_for_current_user(): void
    {
        $rep = $this->makeMobileUser(['username' => 'mobile_channels_'.uniqid()]);
        $template = Sale::query()->where('channel', 'mobile')->firstOrFail();

        Sale::create([
            'order_num' => 92001,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $rep->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'paid',
            'total_vat' => 10,
            'order_total' => 100,
            'payment_status' => 'paid',
            'amount_paid' => 100,
        ]);

        Sale::create([
            'order_num' => 92002,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'pos',
            'cashier_id' => $rep->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'paid',
            'total_vat' => 20,
            'order_total' => 200,
            'payment_status' => 'paid',
            'amount_paid' => 200,
        ]);

        $token = $this->loginMobile($rep);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_no', 92001);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/orders?all_channels=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_mobile_dashboard_admin_only_sees_own_orders(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $otherRep = $this->makeMobileUser(['username' => 'mobile_admin_scope_'.uniqid()]);
        $template = Sale::query()->where('channel', 'mobile')->firstOrFail();

        Sale::create([
            'order_num' => 93001,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $otherRep->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'paid',
            'total_vat' => 10,
            'order_total' => 100,
            'payment_status' => 'paid',
            'amount_paid' => 100,
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $admin->username,
            'password' => 'password',
            'client_id' => 'MOBILE_ADMIN_'.uniqid(),
            'login_channel' => 'mobile',
        ])->assertOk()->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/mobile/orders')
            ->assertOk()
            ->assertJsonMissing(['order_no' => 93001]);
    }

    public function test_mobile_order_detail_returns_line_items(): void
    {
        $rep = $this->makeMobileUser();
        $sale = Sale::query()
            ->where('channel', 'mobile')
            ->firstOrFail();

        $sale->update(['cashier_id' => $rep->id]);

        $token = $this->loginMobile($rep);

        $this->withToken($token)
            ->getJson("/api/v1/mobile/orders/{$sale->id}")
            ->assertOk()
            ->assertJsonStructure([
                'order_no',
                'customer_name',
                'orderTotals',
                'status_name',
                'items' => [['product_code', 'product_name', 'qty', 'unit_price', 'amount']],
            ]);
    }

    public function test_mobile_session_cannot_access_admin_users_api(): void
    {
        $user = $this->makeMobileUser();
        $token = $this->loginMobile($user);

        $this->withToken($token)
            ->getJson('/api/v1/users')
            ->assertStatus(403)
            ->assertJsonPath('code', 'login_channel_forbidden');
    }

    protected function makeMobileUser(array $overrides = []): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        return User::create(array_merge([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'mobile_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'Mobile Rep',
            'is_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['mobile'],
            'is_active' => true,
        ], $overrides));
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
