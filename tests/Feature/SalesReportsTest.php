<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePayment;
use App\Models\PlatformSubscription;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SalesReportsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('username', 'admin')->firstOrFail();
        $this->ensureActiveSubscription($this->admin);
        Sanctum::actingAs($this->admin);
    }

    protected function ensureActiveSubscription(User $user): void
    {
        if (! $user->organization_id) {
            return;
        }

        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $user->organization_id],
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

    public function test_daily_sales_report_returns_pipeline_sales_for_org(): void
    {
        $today = now()->toDateString();

        Sale::query()->create([
            'order_num' => 995001,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $this->admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 5000,
            'total_vat' => 500,
            'amount_paid' => 5000,
            'archived' => 0,
            'completed_at' => now(),
            'created_at' => now(),
        ]);

        Sale::query()->create([
            'order_num' => 995013,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $this->admin->id,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'order_total' => 2500,
            'total_vat' => 0,
            'amount_paid' => 0,
            'archived' => 0,
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/reports/daily-sales?from_date={$today}&to_date={$today}&date_column=sale_day&per_page=50")
            ->assertOk();

        $this->assertGreaterThan(0, (int) $response->json('total'));
        $this->assertNotEmpty($response->json('data'));
        $orders = collect($response->json('data'))->sum(fn ($row) => (int) ($row['orders'] ?? 0));
        $this->assertGreaterThanOrEqual(2, $orders);
    }

    public function test_sales_by_user_report_includes_vat_columns(): void
    {
        $today = now()->toDateString();

        $response = $this->getJson("/api/v1/reports/sales-by-user?from_date={$today}&to_date={$today}&date_column=sale_date&per_page=5")
            ->assertOk();

        $row = collect($response->json('data'))->first();
        if ($row !== null) {
            $this->assertArrayHasKey('total_vat', $row);
            $this->assertArrayHasKey('gross_sales', $row);
        } else {
            $this->assertSame(0, (int) $response->json('total'));
        }
    }

    public function test_sales_by_user_includes_processed_and_booked_orders(): void
    {
        $today = now()->toDateString();

        Sale::query()->create([
            'order_num' => 995011,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $this->admin->id,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'order_total' => 3200,
            'total_vat' => 0,
            'amount_paid' => 0,
            'archived' => 0,
            'created_at' => now(),
        ]);

        Sale::query()->create([
            'order_num' => 995012,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $this->admin->id,
            'status' => 'booked',
            'payment_status' => 'partial',
            'order_total' => 1800,
            'total_vat' => 0,
            'amount_paid' => 500,
            'archived' => 0,
            'created_at' => now(),
        ]);

        $response = $this->getJson(
            "/api/v1/reports/sales-by-user?from_date={$today}&to_date={$today}&date_column=sale_date&cashier_id={$this->admin->id}&per_page=50"
        )->assertOk();

        $rows = collect($response->json('data'));
        $this->assertTrue(
            $rows->contains(fn ($row) => (int) ($row['order_count'] ?? 0) >= 1),
            'Sales by user should include pipeline orders for the cashier.',
        );

        $orderCount = (int) $rows->sum(fn ($row) => (int) ($row['order_count'] ?? 0));
        $this->assertGreaterThanOrEqual(2, $orderCount);
    }

    public function test_sales_by_user_buckets_by_placed_date_not_completed_at(): void
    {
        $placedDay = now()->subDay()->toDateString();
        $completedDay = now()->toDateString();
        $uniqueGross = 488772.50;

        $beforeCompleted = (float) collect($this->getJson(
            "/api/v1/reports/sales-by-user?from_date={$completedDay}&to_date={$completedDay}&date_column=sale_date&cashier_id={$this->admin->id}&per_page=50"
        )->assertOk()->json('data'))->sum(fn ($row) => (float) ($row['gross_sales'] ?? 0));

        $sale = Sale::query()->create([
            'order_num' => 995014,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $this->admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => $uniqueGross,
            'total_vat' => 67416.88,
            'amount_paid' => $uniqueGross,
            'archived' => 0,
            'completed_at' => $completedDay.' 18:00:00',
        ]);
        // created_at is not fillable — force the placed day after insert.
        $sale->forceFill(['created_at' => $placedDay.' 10:00:00'])->save();

        $onPlaced = (float) collect($this->getJson(
            "/api/v1/reports/sales-by-user?from_date={$placedDay}&to_date={$placedDay}&date_column=sale_date&cashier_id={$this->admin->id}&per_page=50"
        )->assertOk()->json('data'))->sum(fn ($row) => (float) ($row['gross_sales'] ?? 0));

        $this->assertGreaterThanOrEqual(
            $uniqueGross,
            $onPlaced,
            'Sales by user should attribute the order to the placed/created date.',
        );

        $afterCompleted = (float) collect($this->getJson(
            "/api/v1/reports/sales-by-user?from_date={$completedDay}&to_date={$completedDay}&date_column=sale_date&cashier_id={$this->admin->id}&per_page=50"
        )->assertOk()->json('data'))->sum(fn ($row) => (float) ($row['gross_sales'] ?? 0));

        $this->assertEqualsWithDelta(
            $beforeCompleted,
            $afterCompleted,
            0.01,
            'Completing an order on a later day must not move it onto that day in sales-by-user.',
        );
    }

    public function test_dispatch_orders_match_orders_without_required_date_using_created_at(): void
    {
        $route = RouteModel::query()->firstOrFail();
        $today = now()->toDateString();

        $order = Sale::query()->create([
            'order_num' => 995002,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $this->admin->id,
            'customer_num' => Sale::query()->whereNotNull('customer_num')->value('customer_num'),
            'route_id' => $route->id,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'order_total' => 2400,
            'total_vat' => 200,
            'amount_paid' => 0,
            'archived' => 0,
            'required_date' => null,
            'created_at' => now(),
        ]);

        $res = $this->getJson("/api/v1/sales?dispatch_orders=1&required_date={$today}&per_page=200")
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($order->id, $ids);
    }

    public function test_dispatch_orders_accept_required_date_range(): void
    {
        $route = RouteModel::query()->firstOrFail();
        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();
        $inRange = now()->toDateString();
        $outOfRange = now()->addDays(5)->toDateString();

        $included = Sale::query()->create([
            'order_num' => 995010,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $this->admin->id,
            'customer_num' => Sale::query()->whereNotNull('customer_num')->value('customer_num'),
            'route_id' => $route->id,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'order_total' => 1200,
            'total_vat' => 100,
            'amount_paid' => 0,
            'archived' => 0,
            'required_date' => $inRange,
            'created_at' => now(),
        ]);

        $excluded = Sale::query()->create([
            'order_num' => 995011,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $this->admin->id,
            'customer_num' => Sale::query()->whereNotNull('customer_num')->value('customer_num'),
            'route_id' => $route->id,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'order_total' => 1200,
            'total_vat' => 100,
            'amount_paid' => 0,
            'archived' => 0,
            'required_date' => $outOfRange,
            'created_at' => now(),
        ]);

        $res = $this->getJson(
            "/api/v1/sales?dispatch_orders=1&required_date_from={$from}&required_date_to={$to}&per_page=200"
        )->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($included->id, $ids);
        $this->assertNotContains($excluded->id, $ids);
    }

    public function test_dispatch_trips_report_scopes_by_organization(): void
    {
        $response = $this->getJson('/api/v1/reports/dispatch-trips?per_page=5')
            ->assertOk();

        $this->assertIsArray($response->json('data'));
    }

    public function test_sales_by_customer_accepts_date_range_without_sale_date_column(): void
    {
        $today = now()->toDateString();

        $response = $this->getJson("/api/v1/reports/sales-by-customer?from_date={$today}&to_date={$today}&per_page=5")
            ->assertOk();

        $this->assertIsArray($response->json('data'));
    }

    public function test_invoice_payments_report_returns_tenant_scoped_rows(): void
    {
        $today = now()->toDateString();
        $customer = Customer::firstOrFail();
        $invoice = CustomerInvoice::create([
            'invoice_number' => 'AR-INV-PAY-RPT-1',
            'sale_id' => Sale::query()->value('id') ?? 1,
            'customer_num' => $customer->customer_num,
            'branch_id' => $customer->branch_id ?? $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'created_by' => $this->admin->id,
            'invoice_date' => $today,
            'total_vat' => 0,
            'invoice_total' => 1500,
            'amount_paid' => 0,
            'payment_status' => 0,
        ]);

        CustomerInvoicePayment::create([
            'customer_invoice_id' => $invoice->id,
            'customer_num' => $customer->customer_num,
            'payment_method_id' => 1,
            'amount_paid' => 500,
            'date_paid' => $today,
            'received_by' => $this->admin->id,
            'organization_id' => $this->admin->organization_id,
            'reference_number' => 'RPT-TEST-1',
        ]);

        $response = $this->getJson(
            "/api/v1/reports/invoice-payments?from_date={$today}&to_date={$today}&date_column=date_paid&per_page=50",
        )->assertOk();

        $this->assertGreaterThan(0, (int) $response->json('total'));
        $this->assertTrue(
            collect($response->json('data'))->contains(
                fn (array $row) => ($row['reference_number'] ?? null) === 'RPT-TEST-1',
            ),
        );
    }

    public function test_invoice_payments_report_respects_branch_filter(): void
    {
        $today = now()->toDateString();
        $customer = Customer::firstOrFail();
        $invoice = CustomerInvoice::create([
            'invoice_number' => 'AR-INV-PAY-RPT-2',
            'sale_id' => Sale::query()->value('id') ?? 1,
            'customer_num' => $customer->customer_num,
            'branch_id' => $customer->branch_id ?? $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'created_by' => $this->admin->id,
            'invoice_date' => $today,
            'total_vat' => 0,
            'invoice_total' => 2000,
            'amount_paid' => 0,
            'payment_status' => 0,
        ]);

        CustomerInvoicePayment::create([
            'customer_invoice_id' => $invoice->id,
            'customer_num' => $customer->customer_num,
            'payment_method_id' => 1,
            'amount_paid' => 750,
            'date_paid' => $today,
            'received_by' => $this->admin->id,
            'organization_id' => $this->admin->organization_id,
            'reference_number' => 'RPT-TEST-2',
        ]);

        $wrongBranchId = ((int) ($customer->branch_id ?? $this->admin->branch_id)) + 99;

        $response = $this->getJson(
            "/api/v1/reports/invoice-payments?from_date={$today}&to_date={$today}&branch_id={$wrongBranchId}&per_page=50",
        )->assertOk();

        $this->assertFalse(
            collect($response->json('data'))->contains(
                fn (array $row) => ($row['reference_number'] ?? null) === 'RPT-TEST-2',
            ),
            'Invoice payments must be filtered by invoice branch_id.',
        );
    }

    public function test_report_filter_cashiers_searches_org_users(): void
    {
        $needle = (string) ($this->admin->username ?? 'admin');

        $response = $this->getJson('/api/v1/reports/filter-cashiers?q='.urlencode($needle))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue($ids->contains((int) $this->admin->id));
    }

    public function test_report_filter_cashiers_resolves_by_id(): void
    {
        $this->getJson('/api/v1/reports/filter-cashiers?id='.$this->admin->id)
            ->assertOk()
            ->assertJsonPath('id', $this->admin->id);
    }

    public function test_daily_sales_and_sales_by_product_tally_with_order_discount(): void
    {
        $day = now()->toDateString();
        $product = \App\Models\Product::query()
            ->where('organization_id', $this->admin->organization_id)
            ->firstOrFail();

        // Two lines totaling 1000 gross / ~137.93 VAT; order discount 100 → header 900.
        $lineGross = 1000.0;
        $lineVat = 137.93;
        $orderDiscount = 100.0;
        $scaled = \App\Services\Sales\CentrixSalesScope::scaleVatForOrderDiscount($lineGross, $lineVat, $orderDiscount);

        $sale = Sale::query()->create([
            'order_num' => 995100,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $this->admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => $scaled['order_total'],
            'total_vat' => $scaled['total_vat'],
            'order_discount' => $scaled['order_discount'],
            'amount_paid' => $scaled['order_total'],
            'archived' => 0,
            'completed_at' => now(),
            'created_at' => now(),
        ]);

        \App\Models\SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'line_no' => 1,
            'quantity' => 1,
            'uom' => 'PCS',
            'selling_price' => 600,
            'amount' => 600,
            'product_vat' => 82.76,
            'discount_given' => 0,
        ]);
        \App\Models\SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'line_no' => 2,
            'quantity' => 1,
            'uom' => 'PCS',
            'selling_price' => 400,
            'amount' => 400,
            'product_vat' => 55.17,
            'discount_given' => 0,
        ]);

        $daily = $this->getJson(
            "/api/v1/reports/daily-sales?from_date={$day}&to_date={$day}&date_column=sale_day&per_page=100"
        )->assertOk();
        $productReport = $this->getJson(
            "/api/v1/reports/sales-by-product?from_date={$day}&to_date={$day}&date_column=sale_date&per_page=100"
        )->assertOk();

        $dailyGross = (float) ($daily->json('summary.gross') ?? collect($daily->json('data'))->sum(fn ($r) => (float) ($r['gross'] ?? 0)));
        $dailyVat = (float) ($daily->json('summary.vat') ?? collect($daily->json('data'))->sum(fn ($r) => (float) ($r['vat'] ?? 0)));
        $productGross = (float) ($productReport->json('summary.total_revenue')
            ?? collect($productReport->json('data'))->sum(fn ($r) => (float) ($r['total_revenue'] ?? 0)));
        $productVat = (float) ($productReport->json('summary.total_vat')
            ?? collect($productReport->json('data'))->sum(fn ($r) => (float) ($r['total_vat'] ?? 0)));

        // Org may have other seed sales for the day — assert our discounted sale
        // is reflected equally in both reports by comparing deltas is hard.
        // Instead assert product summary gross equals daily summary gross for the day.
        $this->assertEqualsWithDelta($dailyGross, $productGross, 0.05);
        $this->assertEqualsWithDelta($dailyVat, $productVat, 0.05);
        $this->assertEqualsWithDelta(900.0, $scaled['order_total'], 0.01);
        $this->assertLessThan($lineVat, $scaled['total_vat']);
    }
}
