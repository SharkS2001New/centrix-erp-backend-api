<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PlatformSubscription;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ShopDebtorsSalesIndexTest extends TestCase
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

    public function test_shop_debtors_lists_unpaid_debtor_orders_and_excludes_route_and_mobile(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $suffix = random_int(100000000, 199999999);
        $debtorNum = $suffix;
        $routeNum = $suffix + 1;

        Customer::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'customer_num' => $debtorNum,
            'customer_name' => 'Shop Debtor '.$suffix,
            'customer_type' => 'debtor',
            'created_by' => $admin->id,
        ]);
        Customer::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'customer_num' => $routeNum,
            'customer_name' => 'Route Customer '.$suffix,
            'customer_type' => 'route',
            'created_by' => $admin->id,
        ]);

        $base = [
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'payment_method_code' => 'CREDIT',
            'is_credit_sale' => 1,
            'order_total' => 1500,
            'amount_paid' => 0,
            'total_vat' => 0,
            'archived' => 0,
            'created_at' => now(),
        ];

        $shopUnpaid = Sale::query()->create(array_merge($base, [
            'order_num' => $suffix,
            'channel' => 'pos',
            'order_source' => 'pos',
            'customer_num' => $debtorNum,
        ]));
        $shopPaid = Sale::query()->create(array_merge($base, [
            'order_num' => $suffix + 1,
            'channel' => 'pos',
            'order_source' => 'pos',
            'customer_num' => $debtorNum,
            'status' => 'paid',
            'payment_status' => 'paid',
            'amount_paid' => 1500,
        ]));
        $routeUnpaid = Sale::query()->create(array_merge($base, [
            'order_num' => $suffix + 2,
            'channel' => 'backend',
            'order_source' => 'backend',
            'customer_num' => $routeNum,
            'route_id' => 1,
        ]));
        $mobileUnpaid = Sale::query()->create(array_merge($base, [
            'order_num' => $suffix + 3,
            'channel' => 'mobile',
            'order_source' => 'mobile',
            'customer_num' => $debtorNum,
        ]));

        $shopPartial = Sale::query()->create(array_merge($base, [
            'order_num' => $suffix + 4,
            'channel' => 'pos',
            'order_source' => 'pos',
            'customer_num' => $debtorNum,
            'status' => 'pending_payment',
            'payment_status' => 'partial',
            'amount_paid' => 500,
        ]));

        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();
        $res = $this->getJson(
            "/api/v1/sales?shop_debtors=1&from_date={$from}&to_date={$to}&per_page=200",
        )->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($shopUnpaid->id, $ids);
        $this->assertContains($shopPartial->id, $ids);
        $this->assertNotContains($shopPaid->id, $ids);
        $this->assertNotContains($routeUnpaid->id, $ids);
        $this->assertContains($mobileUnpaid->id, $ids);

        $unpaidIds = collect(
            $this->getJson(
                "/api/v1/sales?shop_debtors=1&filter[payment_status]=unpaid&from_date={$from}&to_date={$to}&per_page=200",
            )->assertOk()->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($shopUnpaid->id, $unpaidIds);
        $this->assertNotContains($shopPartial->id, $unpaidIds);
        $this->assertNotContains($shopPaid->id, $unpaidIds);

        $partialIds = collect(
            $this->getJson(
                "/api/v1/sales?shop_debtors=1&filter[payment_status]=partial&from_date={$from}&to_date={$to}&per_page=200",
            )->assertOk()->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($shopPartial->id, $partialIds);
        $this->assertNotContains($shopUnpaid->id, $partialIds);
        $this->assertNotContains($shopPaid->id, $partialIds);

        $paidIds = collect(
            $this->getJson(
                "/api/v1/sales?shop_debtors=1&filter[payment_status]=paid&from_date={$from}&to_date={$to}&per_page=200",
            )->assertOk()->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($shopPaid->id, $paidIds);
        $this->assertNotContains($shopUnpaid->id, $paidIds);
        $this->assertNotContains($shopPartial->id, $paidIds);
        $this->assertNotContains($routeUnpaid->id, $paidIds);
        $this->assertNotContains($mobileUnpaid->id, $paidIds);
    }

    public function test_shop_debtors_includes_pos_credit_to_regular_customer_and_completed_unpaid(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $suffix = random_int(200000000, 299999999);
        Customer::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'customer_num' => $suffix,
            'customer_name' => 'Regular Credit '.$suffix,
            'customer_type' => 'regular',
            'created_by' => $admin->id,
        ]);

        $posCredit = Sale::query()->create([
            'order_num' => $suffix,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'cashier_id' => $admin->id,
            'channel' => 'pos',
            'order_source' => 'pos',
            'customer_num' => $suffix,
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'payment_method_code' => 'CREDIT',
            'is_credit_sale' => 1,
            'order_total' => 1100,
            'amount_paid' => 0,
            'total_vat' => 0,
            'archived' => 0,
            'created_at' => now(),
        ]);
        $cashToDebtor = Sale::query()->create([
            'order_num' => $suffix + 1,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'cashier_id' => $admin->id,
            'channel' => 'pos',
            'order_source' => 'pos',
            'customer_num' => $suffix,
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method_code' => 'CASH',
            'is_credit_sale' => 0,
            'order_total' => 500,
            'amount_paid' => 500,
            'total_vat' => 0,
            'archived' => 0,
            'created_at' => now(),
        ]);

        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();

        $unpaidIds = collect(
            $this->getJson(
                "/api/v1/sales?shop_debtors=1&filter[payment_status]=unpaid&from_date={$from}&to_date={$to}&per_page=200",
            )->assertOk()->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($posCredit->id, $unpaidIds);
        $this->assertNotContains($cashToDebtor->id, $unpaidIds);

        $paidIds = collect(
            $this->getJson(
                "/api/v1/sales?shop_debtors=1&filter[payment_status]=paid&from_date={$from}&to_date={$to}&per_page=200",
            )->assertOk()->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($cashToDebtor->id, $paidIds);
        $this->assertNotContains($posCredit->id, $paidIds);
    }

    public function test_shop_debtors_includes_backoffice_and_whatsapp_unpaid_even_with_inherited_route(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $suffix = random_int(300000000, 399999999);
        Customer::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'customer_num' => $suffix,
            'customer_name' => 'Backoffice Debtor '.$suffix,
            'customer_type' => 'debtor',
            'created_by' => $admin->id,
        ]);
        Customer::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'customer_num' => $suffix + 1,
            'customer_name' => 'Regular Save '.$suffix,
            'customer_type' => 'regular',
            'created_by' => $admin->id,
        ]);

        $base = [
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'order_total' => 2200,
            'amount_paid' => 0,
            'total_vat' => 0,
            'archived' => 0,
            'created_at' => now(),
        ];

        $backofficeCredit = Sale::query()->create(array_merge($base, [
            'order_num' => $suffix,
            'channel' => 'backend',
            'order_source' => 'backend',
            'customer_num' => $suffix,
            'route_id' => 1,
            'payment_method_code' => 'CREDIT',
            'is_credit_sale' => 1,
        ]));
        $backofficeSaveUnpaid = Sale::query()->create(array_merge($base, [
            'order_num' => $suffix + 1,
            'channel' => 'backend',
            'order_source' => 'backend',
            'customer_num' => $suffix + 1,
            'payment_method_code' => 'CASH',
            'is_credit_sale' => 0,
        ]));
        $whatsappUnpaid = Sale::query()->create(array_merge($base, [
            'order_num' => $suffix + 2,
            'channel' => 'whatsapp',
            'order_source' => 'whatsapp',
            'customer_num' => $suffix,
            'payment_method_code' => 'CREDIT',
            'is_credit_sale' => 1,
        ]));

        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();
        $unpaidIds = collect(
            $this->getJson(
                "/api/v1/sales?shop_debtors=1&filter[payment_status]=unpaid&from_date={$from}&to_date={$to}&per_page=200",
            )->assertOk()->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($backofficeCredit->id, $unpaidIds);
        $this->assertContains($backofficeSaveUnpaid->id, $unpaidIds);
        $this->assertContains($whatsappUnpaid->id, $unpaidIds);
    }

    public function test_shop_debtors_includes_older_converted_unpaid_within_orders_window(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $org = \App\Models\Organization::query()->findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $sales = is_array($settings['sales'] ?? null) ? $settings['sales'] : [];
        $sales['convert_to_unpaid_statuses'] = ['paid', 'pending_payment', 'completed', 'mobile', 'whatsapp'];
        $settings['sales'] = $sales;
        $org->module_settings = $settings;
        $org->save();

        $suffix = random_int(400000000, 499999999);
        Customer::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'customer_num' => $suffix,
            'customer_name' => 'Older Debtor '.$suffix,
            'customer_type' => 'debtor',
            'created_by' => $admin->id,
        ]);

        $placedAt = now()->subDays(10);
        $sale = Sale::query()->create([
            'order_num' => $suffix,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'cashier_id' => $admin->id,
            'channel' => 'pos',
            'order_source' => 'pos',
            'customer_num' => $suffix,
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method_code' => 'CASH',
            'is_credit_sale' => 0,
            'order_total' => 2500,
            'amount_paid' => 2500,
            'total_vat' => 0,
            'archived' => 0,
            'created_at' => $placedAt,
            'completed_at' => $placedAt,
        ]);
        if (\Illuminate\Support\Facades\Schema::hasColumn('sales', 'effective_sale_date')) {
            $sale->forceFill(['effective_sale_date' => $placedAt->toDateString()])->saveQuietly();
        }

        $converted = app(\App\Services\Sales\SalePaymentStatusConversionService::class)
            ->convertToUnpaid($sale->fresh(), $admin);

        $this->assertSame('unpaid', $converted->payment_status);
        $this->assertTrue((bool) $converted->is_credit_sale);
        $this->assertSame('CREDIT', strtoupper((string) $converted->payment_method_code));

        // Keep the original sale calendar day so a 3-day UI window would hide it while
        // the orders-list (14-day) window still includes it.
        $oldDay = $placedAt->toDateString();
        if (\Illuminate\Support\Facades\Schema::hasColumn('sales', 'effective_sale_date')) {
            $converted->forceFill(['effective_sale_date' => $oldDay])->saveQuietly();
        }

        $from = now()->subDays(14)->toDateString();
        $to = now()->toDateString();
        $ids = collect(
            $this->getJson(
                "/api/v1/sales?shop_debtors=1&filter[payment_status]=unpaid&from_date={$from}&to_date={$to}&per_page=200",
            )->assertOk()->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($sale->id, $ids);

        $narrowFrom = now()->subDays(2)->toDateString();
        $narrowIds = collect(
            $this->getJson(
                "/api/v1/sales?shop_debtors=1&filter[payment_status]=unpaid&from_date={$narrowFrom}&to_date={$to}&per_page=200",
            )->assertOk()->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains($sale->id, $narrowIds);
    }

    public function test_shop_debtors_includes_pos_paid_order_after_convert_to_unpaid_for_debtor(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $org = \App\Models\Organization::query()->findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $sales = is_array($settings['sales'] ?? null) ? $settings['sales'] : [];
        $sales['convert_to_unpaid_statuses'] = ['paid', 'pending_payment', 'completed', 'mobile', 'whatsapp'];
        $settings['sales'] = $sales;
        $org->module_settings = $settings;
        $org->save();

        $suffix = random_int(500000000, 599999999);
        Customer::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'customer_num' => $suffix,
            'customer_name' => 'Debtor Convert '.$suffix,
            'customer_type' => 'debtor',
            'created_by' => $admin->id,
        ]);

        $placedAt = now()->subDays(2);
        $sale = Sale::query()->create([
            'order_num' => $suffix,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'cashier_id' => $admin->id,
            'channel' => 'pos',
            'order_source' => 'pos',
            'customer_num' => $suffix,
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method_code' => 'CASH',
            'is_credit_sale' => 0,
            'order_total' => 618100,
            'amount_paid' => 618100,
            'total_vat' => 0,
            'archived' => 0,
            'created_at' => $placedAt,
            'completed_at' => $placedAt,
        ]);

        $this->postJson("/api/v1/sales/{$sale->id}/convert-to-unpaid")->assertOk();

        $from = now()->subDays(6)->toDateString();
        $to = now()->toDateString();
        $unpaidIds = collect(
            $this->getJson(
                "/api/v1/sales?shop_debtors=1&filter[payment_status]=unpaid&from_date={$from}&to_date={$to}&date_field=placed&per_page=200",
            )->assertOk()->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($sale->id, $unpaidIds);
    }

    public function test_shop_debtors_unpaid_matches_unpaid_orders_for_regular_customer_cash_sale(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $suffix = random_int(800000000, 899999999);
        Customer::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'customer_num' => $suffix,
            'customer_name' => 'Regular Cash '.$suffix,
            'customer_type' => 'regular',
            'created_by' => $admin->id,
        ]);

        $sale = Sale::query()->create([
            'order_num' => $suffix,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'cashier_id' => $admin->id,
            'channel' => 'pos',
            'order_source' => 'pos',
            'customer_num' => $suffix,
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'payment_method_code' => 'CASH',
            'is_credit_sale' => 0,
            'order_total' => 12000,
            'amount_paid' => 0,
            'total_vat' => 0,
            'archived' => 0,
            'created_at' => now(),
        ]);

        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();
        $query = "from_date={$from}&to_date={$to}&date_field=placed&per_page=200";

        $unpaidIds = collect(
            $this->getJson("/api/v1/sales?filter[payment_status]=unpaid&{$query}")->assertOk()->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();

        $shopIds = collect(
            $this->getJson("/api/v1/sales?shop_debtors=1&filter[payment_status]=unpaid&outstanding_balance=1&{$query}")->assertOk()->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($sale->id, $unpaidIds);
        $this->assertContains($sale->id, $shopIds);
    }
}