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

    public function test_mobile_orders_ignore_all_channels_query(): void
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
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_no', 92001);
    }

    public function test_scoped_mobile_user_cannot_include_other_channels(): void
    {
        $rep = $this->makeMobileUser([
            'username' => 'mobile_route_scope_'.uniqid(),
            'mobile_order_scope' => 'route_only',
        ]);
        $template = Sale::query()->where('channel', 'mobile')->firstOrFail();

        Sale::create([
            'order_num' => 92501,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $rep->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id ?? 1,
            'status' => 'paid',
            'total_vat' => 10,
            'order_total' => 100,
            'payment_status' => 'paid',
            'amount_paid' => 100,
        ]);

        Sale::create([
            'order_num' => 92502,
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
            ->getJson('/api/v1/mobile/orders?all_channels=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_no', 92501);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/dashboard?all_channels=1')
            ->assertOk()
            ->assertJsonPath('mobile_context.can_use_all_channels', false)
            ->assertJsonPath('mobile_context.mobile_order_scope', 'route_only');
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

        $sale->update([
            'cashier_id' => $rep->id,
            'status' => 'paid',
        ]);

        $token = $this->loginMobile($rep);

        $this->withToken($token)
            ->getJson("/api/v1/mobile/orders/{$sale->id}")
            ->assertOk()
            ->assertJsonStructure([
                'order_no',
                'customer_name',
                'orderTotals',
                'status_name',
                'can_edit',
                'items' => [['product_code', 'product_name', 'qty', 'unit_price', 'amount']],
            ])
            ->assertJsonPath('can_edit', true);
    }

    public function test_mobile_checkout_without_payment_uses_save_status(): void
    {
        $user = $this->makeMobileUser();
        $product = \App\Models\Product::firstOrFail();
        $customer = \App\Models\Customer::firstOrFail();
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

        $sale = $this->withToken($token)
            ->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
                'customer_num' => $customer->customer_num,
                'payment_method_code' => 'CASH',
            ])
            ->assertCreated()
            ->json();

        $this->assertEquals('unpaid', $sale['status'] ?? null);
        $this->assertEquals('unpaid', $sale['payment_status'] ?? null);
        $this->assertEquals(0, (float) ($sale['amount_paid'] ?? -1));
    }

    public function test_mobile_checkout_payment_mode_requires_payment(): void
    {
        $user = $this->makeMobileUser();
        $this->setMobileCheckoutMode($user, 'payment');
        $product = \App\Models\Product::firstOrFail();
        $customer = \App\Models\Customer::firstOrFail();
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
            ->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
                'customer_num' => $customer->customer_num,
                'payment_method_code' => 'CASH',
            ])
            ->assertUnprocessable()
            ->assertJsonFragment([
                'message' => 'Enter payment details to complete this order.',
            ]);
    }

    public function test_mobile_checkout_ask_mode_honours_save_only_flag(): void
    {
        $user = $this->makeMobileUser();
        $this->setMobileCheckoutMode($user, 'ask');
        $product = \App\Models\Product::firstOrFail();
        $customer = \App\Models\Customer::firstOrFail();
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

        $sale = $this->withToken($token)
            ->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
                'customer_num' => $customer->customer_num,
                'save_only' => true,
            ])
            ->assertCreated()
            ->json();

        $this->assertEquals('unpaid', $sale['status'] ?? null);
        $this->assertEquals(0, (float) ($sale['amount_paid'] ?? -1));
    }

    public function test_mobile_booked_order_from_previous_day_exposes_can_edit(): void
    {
        $template = Sale::query()->where('channel', 'mobile')->firstOrFail();
        $user = $this->makeMobileUser(['assigned_route_id' => $template->route_id]);
        $product = \App\Models\Product::firstOrFail();
        $token = $this->loginMobile($user);

        $sale = Sale::create([
            'order_num' => 97001,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $user->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'booked',
            'total_vat' => 10,
            'order_total' => 100,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'created_at' => now()->subDay(),
        ]);

        \App\Models\SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'line_no' => 1,
            'item_code' => '1',
            'quantity' => 1,
            'uom' => $product->uom,
            'selling_price' => 100,
            'discount_given' => 0,
            'product_vat' => 10,
            'amount' => 100,
            'on_wholesale_retail' => 0,
        ]);

        $this->withToken($token)
            ->getJson("/api/v1/mobile/orders/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('can_edit', true);

        $response = $this->withToken($token)
            ->postJson("/api/v1/sales/orders/{$sale->id}/restore-to-cart", [
                'replace' => true,
            ]);

        $this->assertNotSame(
            'Mobile orders from previous dates cannot be edited, cancelled, or returned.',
            $response->json('message'),
        );
    }

    public function test_mobile_paid_order_from_previous_day_cannot_be_restored_to_cart(): void
    {
        $template = Sale::query()->where('channel', 'mobile')->firstOrFail();
        $user = $this->makeMobileUser(['assigned_route_id' => $template->route_id]);
        $product = \App\Models\Product::firstOrFail();
        $token = $this->loginMobile($user);

        $sale = Sale::create([
            'order_num' => 97002,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $user->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'paid',
            'total_vat' => 10,
            'order_total' => 100,
            'payment_status' => 'paid',
            'amount_paid' => 100,
            'created_at' => now()->subDay(),
        ]);

        \App\Models\SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'line_no' => 1,
            'item_code' => '1',
            'quantity' => 1,
            'uom' => $product->uom,
            'selling_price' => 100,
            'discount_given' => 0,
            'product_vat' => 10,
            'amount' => 100,
            'on_wholesale_retail' => 0,
        ]);

        $this->withToken($token)
            ->getJson("/api/v1/mobile/orders/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('can_edit', false);

        $this->withToken($token)
            ->postJson("/api/v1/sales/orders/{$sale->id}/restore-to-cart", [
                'replace' => true,
            ])
            ->assertStatus(422);
    }

    public function test_mobile_paid_order_cannot_be_restored_to_cart_for_editing(): void
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

        $sale = $this->withToken($token)
            ->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
                'payment_method_code' => 'CASH',
                'pay_now' => 100,
            ])
            ->assertCreated()
            ->json();

        $this->assertEquals('paid', $sale['status'] ?? null);

        $this->withToken($token)
            ->postJson("/api/v1/sales/orders/{$sale['id']}/restore-to-cart", [
                'replace' => true,
            ])
            ->assertStatus(422);

        $this->assertEquals('paid', Sale::find($sale['id'])->status);
    }

    public function test_mobile_paid_order_cannot_be_cancelled(): void
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
                'quantity' => 2,
                'unit_price' => 100,
                'on_wholesale_retail' => 0,
            ])
            ->assertCreated();

        $sale = $this->withToken($token)
            ->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
                'payment_method_code' => 'CASH',
                'pay_now' => 200,
            ])
            ->assertCreated()
            ->json();

        $this->withToken($token)
            ->postJson("/api/v1/sales/orders/{$sale['id']}/cancel")
            ->assertStatus(422);

        $this->assertEquals('paid', Sale::find($sale['id'])->status);
    }

    public function test_mobile_session_can_list_and_create_customers(): void
    {
        $user = $this->makeMobileUser();
        $token = $this->loginMobile($user);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/customers')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);

        $routeId = (int) (\App\Models\RouteModel::query()->value('id') ?? 1);

        $created = $this->withToken($token)
            ->postJson('/api/v1/mobile/customers', [
                'customer_name' => 'Mobile Test Customer '.uniqid(),
                'customer_type' => 'route',
                'route_id' => $routeId,
                'phone_number' => '0711'.random_int(100000, 999999),
                'town' => 'Nairobi',
            ])
            ->assertCreated()
            ->json();

        $this->assertNotEmpty($created['customer_num'] ?? null);

        $this->withToken($token)
            ->postJson('/api/v1/mobile/customers', [
                'customer_name' => 'Duplicate Phone Customer',
                'phone_number' => $created['phone_number'],
            ])
            ->assertStatus(422);
    }

    public function test_mobile_user_always_sees_route_orders_only(): void
    {
        $rep = $this->makeMobileUser([
            'username' => 'mobile_route_rep_'.uniqid(),
            'mobile_order_scope' => 'normal_only',
        ]);
        $template = Sale::query()->where('channel', 'mobile')->firstOrFail();

        Sale::create([
            'order_num' => 93001,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $rep->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id ?? 1,
            'status' => 'paid',
            'total_vat' => 10,
            'order_total' => 100,
            'payment_status' => 'paid',
            'amount_paid' => 100,
        ]);

        Sale::create([
            'order_num' => 93002,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $rep->id,
            'customer_num' => $template->customer_num,
            'route_id' => null,
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
            ->assertJsonPath('data.0.order_no', 93001);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/dashboard')
            ->assertOk()
            ->assertJsonPath('mobile_context.mobile_order_scope', 'route_only')
            ->assertJsonPath('summary.NoofOrders', 1);
    }

    public function test_stored_normal_scope_still_applies_route_only_filtering(): void
    {
        $rep = $this->makeMobileUser([
            'username' => 'mobile_legacy_normal_'.uniqid(),
            'mobile_order_scope' => 'normal_only',
        ]);
        $template = Sale::query()->where('channel', 'mobile')->firstOrFail();

        Sale::create([
            'order_num' => 94001,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $rep->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id ?? 1,
            'status' => 'paid',
            'total_vat' => 10,
            'order_total' => 100,
            'payment_status' => 'paid',
            'amount_paid' => 100,
        ]);

        Sale::create([
            'order_num' => 94002,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $rep->id,
            'customer_num' => $template->customer_num,
            'route_id' => null,
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
            ->assertJsonPath('data.0.order_no', 94001);
    }

    public function test_mobile_user_cannot_create_non_route_customer(): void
    {
        $user = $this->makeMobileUser(['mobile_order_scope' => 'route_only']);
        $token = $this->loginMobile($user);

        $this->withToken($token)
            ->postJson('/api/v1/mobile/customers', [
                'customer_name' => 'Debtor Blocked Customer',
                'customer_type' => 'debtor',
            ])
            ->assertStatus(422);
    }

    public function test_mobile_user_can_create_route_customer(): void
    {
        $user = $this->makeMobileUser(['mobile_order_scope' => 'route_only']);
        $token = $this->loginMobile($user);
        $routeId = (int) (\App\Models\RouteModel::query()->value('id') ?? 1);

        $this->withToken($token)
            ->postJson('/api/v1/mobile/customers', [
                'customer_name' => 'Route Customer '.uniqid(),
                'customer_type' => 'route',
                'route_id' => $routeId,
            ])
            ->assertCreated();
    }

    public function test_mobile_user_can_list_routes_without_fulfillment_permission(): void
    {
        $user = $this->makeMobileUser();
        $token = $this->loginMobile($user);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/routes')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'route_name', 'route_markup_price']],
                'route_selection_locked',
                'assigned_route_id',
            ])
            ->assertJsonPath('route_selection_locked', true)
            ->assertJsonPath('assigned_route_id', $user->assigned_route_id);
    }

    public function test_route_locked_mobile_user_sees_only_assigned_route(): void
    {
        $route = \App\Models\RouteModel::query()->where('is_active', true)->firstOrFail();
        $otherRoute = \App\Models\RouteModel::query()
            ->where('is_active', true)
            ->where('organization_id', $route->organization_id)
            ->where('id', '!=', $route->id)
            ->first();

        if (! $otherRoute) {
            $otherRoute = \App\Models\RouteModel::create([
                'organization_id' => $route->organization_id,
                'route_name' => 'Test Route '.uniqid(),
                'route_markup_price' => 0,
                'direction' => 'north',
                'is_active' => true,
            ]);
        }

        $user = $this->makeMobileUser([
            'assigned_route_id' => $route->id,
        ]);
        $token = $this->loginMobile($user);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/routes')
            ->assertOk()
            ->assertJsonPath('route_selection_locked', true)
            ->assertJsonPath('assigned_route_id', $route->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $route->id);
    }

    public function test_mobile_routes_list_is_scoped_to_user_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgA = \App\Models\Organization::findOrFail($admin->organization_id);

        $orgB = \App\Models\Organization::create([
            'company_code' => 'RT'.substr(uniqid(), -4),
            'org_name' => 'Routes Isolation Tenant',
            'org_email' => 'routes-isolate@example.com',
            'primary_tel' => '0700000101',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => $orgA->enabled_modules,
            'module_settings' => $orgA->module_settings,
        ]);

        $routeA = \App\Models\RouteModel::query()
            ->where('organization_id', $orgA->id)
            ->where('is_active', true)
            ->firstOrFail();

        $routeB = \App\Models\RouteModel::create([
            'organization_id' => $orgB->id,
            'route_name' => 'Other Org Route '.uniqid(),
            'route_markup_price' => 0,
            'direction' => 'east',
            'is_active' => true,
        ]);

        $user = $this->makeMobileUser([
            'assigned_route_id' => null,
        ]);
        $token = $this->loginMobile($user);

        $ids = collect(
            $this->withToken($token)
                ->getJson('/api/v1/mobile/routes')
                ->assertOk()
                ->json('data'),
        )->pluck('id')->all();

        $this->assertContains($routeA->id, $ids);
        $this->assertNotContains($routeB->id, $ids);
    }

    public function test_mobile_user_cannot_patch_cart_with_route_from_other_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgA = \App\Models\Organization::findOrFail($admin->organization_id);

        $orgB = \App\Models\Organization::create([
            'company_code' => 'RC'.substr(uniqid(), -4),
            'org_name' => 'Route Cart Isolation Tenant',
            'org_email' => 'route-cart@example.com',
            'primary_tel' => '0700000102',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => $orgA->enabled_modules,
            'module_settings' => $orgA->module_settings,
        ]);

        $otherRoute = \App\Models\RouteModel::create([
            'organization_id' => $orgB->id,
            'route_name' => 'Foreign Route '.uniqid(),
            'route_markup_price' => 0,
            'direction' => 'west',
            'is_active' => true,
        ]);

        $user = $this->makeMobileUser(['assigned_route_id' => null]);
        $token = $this->loginMobile($user);

        $cart = $this->withToken($token)
            ->postJson('/api/v1/sales/carts', [
                'channel' => 'mobile',
                'branch_id' => $user->branch_id,
            ])
            ->assertCreated()
            ->json();

        $this->withToken($token)
            ->patchJson("/api/v1/sales/carts/{$cart['id']}", [
                'route_id' => $otherRoute->id,
            ])
            ->assertStatus(422);
    }

    public function test_organization_preview_returns_org_name(): void
    {
        $this->getJson('/api/v1/auth/organization-preview?company_code=DEMO')
            ->assertOk()
            ->assertJsonStructure(['company_code', 'org_name'])
            ->assertJsonMissing(['organization_id']);
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

    public function test_mobile_orders_list_includes_connectivity_fields(): void
    {
        $rep = $this->makeMobileUser();
        $template = Sale::query()
            ->where('channel', 'mobile')
            ->firstOrFail();

        Sale::create([
            'order_num' => 94001,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $rep->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'unpaid',
            'total_vat' => 10,
            'order_total' => 100,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'fulfillment_meta' => [
                'location_check' => ['offline_order' => true, 'verified' => false],
            ],
        ]);

        $token = $this->loginMobile($rep);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/orders')
            ->assertOk()
            ->assertJsonFragment([
                'order_no' => 94001,
                'order_connectivity' => 'offline',
                'is_offline_order' => true,
            ]);
    }

    public function test_mobile_dashboard_recent_orders_exclude_workflow_queue_statuses(): void
    {
        $rep = $this->makeMobileUser(['username' => 'mobile_recent_'.uniqid()]);
        $template = Sale::query()->where('channel', 'mobile')->firstOrFail();

        Sale::create([
            'order_num' => 95001,
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
            'order_num' => 95002,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $rep->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'pending_approval',
            'total_vat' => 20,
            'order_total' => 200,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        Sale::create([
            'order_num' => 95003,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $rep->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'editable',
            'total_vat' => 30,
            'order_total' => 300,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        $token = $this->loginMobile($rep);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/dashboard')
            ->assertOk()
            ->assertJsonCount(1, 'recent_orders')
            ->assertJsonPath('recent_orders.0.order_no', 95001);
    }

    protected function setMobileCheckoutMode(User $user, string $mode): void
    {
        $org = $user->organization()->firstOrFail();
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'mobile_checkout_mode' => $mode,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    protected function makeMobileUser(array $overrides = []): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $routeId = (int) (\App\Models\RouteModel::query()->value('id') ?? 1);

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
            'mobile_order_scope' => 'route_only',
            'assigned_route_id' => $routeId,
            'is_active' => true,
        ], $overrides));
    }

    public function test_mobile_orders_list_includes_line_discount_in_total_discount(): void
    {
        $rep = $this->makeMobileUser(['username' => 'mobile_disc_'.uniqid()]);
        $template = Sale::query()->where('channel', 'mobile')->firstOrFail();
        $product = \App\Models\Product::query()->firstOrFail();

        $sale = Sale::create([
            'order_num' => 96001,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $rep->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'paid',
            'total_vat' => 10,
            'order_total' => 90,
            'order_discount' => 10,
            'payment_status' => 'paid',
            'amount_paid' => 90,
        ]);

        \App\Models\SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'line_no' => 1,
            'item_code' => '1',
            'quantity' => 1,
            'uom' => $product->uom,
            'selling_price' => 100,
            'discount_given' => 25,
            'product_vat' => 10,
            'amount' => 75,
            'on_wholesale_retail' => 0,
        ]);

        $token = $this->loginMobile($rep);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/orders')
            ->assertOk()
            ->assertJsonPath('data.0.total_discount', 35.0);
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
