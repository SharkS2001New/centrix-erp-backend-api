<?php

namespace Tests\Feature;

use App\Models\PlatformSubscription;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SaleOrderListDateFilterTest extends TestCase
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

    public function test_sales_list_includes_orders_without_completed_at_when_filtering_by_today(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $today = now()->toDateString();

        $sale = Sale::query()->create([
            'order_num' => 993001,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'paid',
            'payment_status' => 'paid',
            'order_total' => 250,
            'amount_paid' => 250,
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/sales?from_date={$today}&to_date={$today}&per_page=200");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($sale->id), 'Paid order without completed_at should appear in today\'s list.');
    }

    public function test_sales_list_excludes_orders_outside_date_range(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $sale = Sale::query()->create([
            'order_num' => 993002,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'order_total' => 100,
            'amount_paid' => 0,
            'completed_at' => null,
        ]);
        $twoDaysAgo = now()->subDays(2);
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $sale->id)->update(array_filter([
            'created_at' => $twoDaysAgo,
            'effective_sale_date' => \Illuminate\Support\Facades\Schema::hasColumn('sales', 'effective_sale_date')
                ? $twoDaysAgo->toDateString()
                : null,
        ], fn ($v) => $v !== null));

        $response = $this->getJson("/api/v1/sales?from_date={$yesterday}&to_date={$today}&per_page=200");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($sale->id));
    }

    public function test_sales_list_filters_by_payment_status_independent_of_workflow_status(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $processedUnpaid = Sale::query()->create([
            'order_num' => 993003,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'is_credit_sale' => true,
            'order_total' => 800,
            'amount_paid' => 0,
        ]);

        $processedPaid = Sale::query()->create([
            'order_num' => 993004,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'processed',
            'payment_status' => 'paid',
            'order_total' => 900,
            'amount_paid' => 900,
        ]);

        $response = $this->getJson('/api/v1/sales?filter[payment_status]=unpaid&per_page=200');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($processedUnpaid->id));
        $this->assertFalse($ids->contains($processedPaid->id));
    }

    public function test_payment_status_unpaid_queue_excludes_completed_and_fully_paid_orders(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $completedUnpaidLabel = Sale::query()->create([
            'order_num' => 993005,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'order_total' => 500,
            'amount_paid' => 500,
        ]);

        $deliveredUnpaid = Sale::query()->create([
            'order_num' => 993006,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'delivered',
            'payment_status' => 'unpaid',
            'order_total' => 1200,
            'amount_paid' => 0,
        ]);

        $response = $this->getJson('/api/v1/sales?filter[payment_status]=unpaid&per_page=200');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($completedUnpaidLabel->id));
        $this->assertTrue($ids->contains($deliveredUnpaid->id));
    }

    public function test_sales_list_orders_newest_first_by_date_by_default(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $older = Sale::query()->create([
            'order_num' => 993101,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'booked',
            'payment_status' => 'unpaid',
            'order_total' => 100,
            'amount_paid' => 0,
            'completed_at' => null,
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

        $newer = Sale::query()->create([
            'order_num' => 993100,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'status' => 'booked',
            'payment_status' => 'unpaid',
            'order_total' => 200,
            'amount_paid' => 0,
            'completed_at' => null,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();
        $response = $this->getJson("/api/v1/sales?from_date={$from}&to_date={$to}&per_page=200&sort=-created_at");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $newerPos = array_search((int) $newer->id, $ids, true);
        $olderPos = array_search((int) $older->id, $ids, true);

        $this->assertNotFalse($newerPos);
        $this->assertNotFalse($olderPos);
        $this->assertLessThan($olderPos, $newerPos, 'Newer orders must appear before older ones.');
    }

    public function test_sales_list_defaults_to_hot_window_when_dates_omitted(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $old = Sale::query()->create([
            'order_num' => 993201,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 80,
            'amount_paid' => 80,
            'completed_at' => now()->subDays(20),
            'archived' => 0,
        ]);
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $old->id)->update([
            'created_at' => now()->subDays(20),
            'completed_at' => now()->subDays(20),
        ]);

        $recent = Sale::query()->create([
            'order_num' => 993202,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 90,
            'amount_paid' => 90,
            'completed_at' => now(),
            'archived' => 0,
        ]);

        $response = $this->getJson('/api/v1/sales?per_page=200&date_field=placed');

        $response->assertOk()
            ->assertJsonPath('list_scope.applied', true)
            ->assertJsonPath('list_scope.skipped_for_search', false);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($recent->id));
        $this->assertFalse($ids->contains($old->id));
    }

    public function test_sales_list_search_respects_explicit_date_filter(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $withinMonth = Sale::query()->create([
            'order_num' => 993301,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 55,
            'amount_paid' => 55,
            'completed_at' => now()->subDays(20),
            'archived' => 0,
            'customer_name_override' => 'Within Month Co',
        ]);
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $withinMonth->id)->update([
            'created_at' => now()->subDays(20),
            'completed_at' => now()->subDays(20),
        ]);

        $tooOld = Sale::query()->create([
            'order_num' => 993302,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 60,
            'amount_paid' => 60,
            'completed_at' => now()->subDays(45),
            'archived' => 0,
            'customer_name_override' => 'Too Old Co',
        ]);
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $tooOld->id)->update([
            'created_at' => now()->subDays(45),
            'completed_at' => now()->subDays(45),
        ]);

        // Explicit From/To must not be widened by search — today-only filter excludes older matches.
        $today = now()->toDateString();
        $response = $this->getJson("/api/v1/sales?q=Within%20Month&from_date={$today}&to_date={$today}&per_page=50&date_field=placed");

        $response->assertOk()
            ->assertJsonPath('list_scope.skipped_for_search', false)
            ->assertJsonPath('list_scope.search_window', false)
            ->assertJsonPath('list_scope.from', $today)
            ->assertJsonPath('list_scope.to', $today);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($withinMonth->id));

        $oldName = $this->getJson("/api/v1/sales?q=Too%20Old&from_date={$today}&to_date={$today}&per_page=50&date_field=placed");
        $oldIds = collect($oldName->json('data'))->pluck('id');
        $this->assertFalse($oldIds->contains($tooOld->id));
    }

    public function test_sales_list_search_without_dates_uses_search_window(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $withinMonth = Sale::query()->create([
            'order_num' => 993303,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 55,
            'amount_paid' => 55,
            'completed_at' => now()->subDays(20),
            'archived' => 0,
            'customer_name_override' => 'Search Window Co',
        ]);
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $withinMonth->id)->update([
            'created_at' => now()->subDays(20),
            'completed_at' => now()->subDays(20),
        ]);

        $response = $this->getJson('/api/v1/sales?q=Search%20Window&per_page=50&date_field=placed');

        $response->assertOk()
            ->assertJsonPath('list_scope.skipped_for_search', false)
            ->assertJsonPath('list_scope.search_window', true);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($withinMonth->id));
    }

    public function test_sales_list_exact_order_lookup_respects_explicit_date_filter(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $old = Sale::query()->create([
            'order_num' => 993304,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'paid',
            'payment_status' => 'paid',
            'order_total' => 55,
            'amount_paid' => 55,
            'completed_at' => now()->subDays(45),
            'archived' => 0,
        ]);
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $old->id)->update([
            'created_at' => now()->subDays(45),
            'completed_at' => now()->subDays(45),
        ]);

        $today = now()->toDateString();
        $response = $this->getJson(
            "/api/v1/sales?q=993304&from_date={$today}&to_date={$today}&per_page=50&date_field=placed",
        );

        $response->assertOk()
            ->assertJsonPath('list_scope.skipped_for_search', false)
            ->assertJsonPath('list_scope.from', $today);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($old->id));
    }

    public function test_sales_list_exact_order_number_lookup_skips_date_window(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $old = Sale::query()->create([
            'order_num' => 168,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'paid',
            'payment_status' => 'paid',
            'order_total' => 55,
            'amount_paid' => 55,
            'completed_at' => now()->subDays(45),
            'archived' => 0,
        ]);
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $old->id)->update([
            'created_at' => now()->subDays(45),
            'completed_at' => now()->subDays(45),
        ]);

        foreach (['168', 's0168', 'S0168'] as $q) {
            $response = $this->getJson('/api/v1/sales?q='.urlencode($q).'&per_page=50&date_field=placed');
            $response->assertOk()
                ->assertJsonPath('list_scope.skipped_for_search', true);
            $ids = collect($response->json('data'))->pluck('id');
            $this->assertTrue($ids->contains($old->id), "Expected order 168 for query {$q}");
        }
    }

    public function test_sales_list_exact_order_lookup_includes_archived_sales(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $archived = Sale::query()->create([
            'order_num' => 60,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 120,
            'amount_paid' => 120,
            'completed_at' => now()->subDays(120),
            'archived' => 1,
        ]);
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $archived->id)->update([
            'created_at' => now()->subDays(120),
            'completed_at' => now()->subDays(120),
            'archived' => 1,
        ]);

        // Default list browse still hides archived rows.
        $browse = $this->getJson('/api/v1/sales?per_page=50&date_field=placed');
        $browse->assertOk();
        $this->assertFalse(
            collect($browse->json('data'))->pluck('id')->contains($archived->id),
            'Archived sales must stay hidden from default sales list browse',
        );

        // Returns / invoice exact lookup must find S0060 even when archived.
        foreach (['60', 'S0060', 's0060', 'S60'] as $q) {
            $response = $this->getJson(
                '/api/v1/sales?q='.urlencode($q)
                .'&per_page=15'
                .'&exclude_statuses='.urlencode('cancelled,expired,held,draft,pending_approval')
                .'&date_field=placed',
            );
            $response->assertOk();
            $ids = collect($response->json('data'))->pluck('id');
            $this->assertTrue($ids->contains($archived->id), "Expected archived order 60 for query {$q}");
        }

        // Explicit include_archived also works for free-text / status-filtered return search.
        $withFlag = $this->getJson(
            '/api/v1/sales?q=S0060&include_archived=1&per_page=15&date_field=placed',
        );
        $withFlag->assertOk();
        $this->assertTrue(collect($withFlag->json('data'))->pluck('id')->contains($archived->id));
    }

    public function test_sales_list_search_accepts_s_prefixed_order_number(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $sale = Sale::query()->create([
            'order_num' => 34,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 40,
            'amount_paid' => 40,
            'completed_at' => now()->subDays(3),
            'archived' => 0,
        ]);
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $sale->id)->update([
            'created_at' => now()->subDays(3),
            'completed_at' => now()->subDays(3),
        ]);

        $response = $this->getJson('/api/v1/sales?q=s0034&per_page=50&date_field=placed');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($sale->id));
    }

    public function test_sales_list_summary_aggregates_all_filtered_rows_not_current_page(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $marker = 'SummaryAggTest'.uniqid('', true);
        $base = [
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_name_override' => $marker,
            'status' => 'paid',
            'payment_status' => 'paid',
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'archived' => 0,
        ];

        Sale::query()->create(array_merge($base, ['order_num' => 994001, 'order_total' => 100, 'amount_paid' => 100]));
        Sale::query()->create(array_merge($base, ['order_num' => 994002, 'order_total' => 200, 'amount_paid' => 200]));
        Sale::query()->create(array_merge($base, [
            'order_num' => 994003,
            'order_total' => 50,
            'amount_paid' => 50,
            'status' => 'cancelled',
            'payment_status' => 'unpaid',
        ]));

        $response = $this->getJson('/api/v1/sales?q='.urlencode($marker).'&per_page=1&date_field=placed');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.revenue', 300)
            ->assertJsonPath('summary.paid', 2)
            ->assertJsonPath('summary.cancelled', 1);
    }

    public function test_sales_list_with_route_filter_summary_does_not_break_only_full_group_by(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $route = \App\Models\RouteModel::query()
            ->where('organization_id', $admin->organization_id)
            ->first();
        $this->assertNotNull($route);

        $sale = Sale::query()->create([
            'order_num' => 994101,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'order_source' => 'mobile',
            'cashier_id' => $admin->id,
            'route_id' => $route->id,
            'status' => 'paid',
            'payment_status' => 'paid',
            'order_total' => 175,
            'amount_paid' => 175,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'archived' => 0,
        ]);

        $response = $this->getJson(
            '/api/v1/sales?channel=mobile&route_id='.$route->id.'&per_page=25&date_field=placed'
            .'&from_date='.now()->subDays(7)->toDateString()
            .'&to_date='.now()->toDateString()
        );

        $response->assertOk()
            ->assertJsonStructure(['data', 'summary' => ['total', 'revenue', 'paid']]);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($sale->id));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('summary.total'));
    }

    public function test_sales_list_search_finds_orders_by_product_name(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $marker = 'GlobProd'.uniqid('', true);
        $product = \App\Models\Product::query()->create([
            'product_code' => 'GP-'.substr(uniqid('', true), -8),
            'product_name' => $marker.' Cooking Oil 5L',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'vat_id' => 1,
            'unit_price' => 500,
            'discount_percentage' => 0,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'stock_in_shop' => 1,
            'stock_in_store' => 0,
        ]);

        $withProduct = Sale::query()->create([
            'order_num' => 995101,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_name_override' => 'Buyer With Oil',
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 500,
            'amount_paid' => 500,
            'completed_at' => now(),
            'archived' => 0,
        ]);
        \App\Models\SaleItem::query()->create([
            'sale_id' => $withProduct->id,
            'product_code' => $product->product_code,
            'line_no' => 1,
            'item_code' => '1',
            'quantity' => 1,
            'uom' => 'PCS',
            'selling_price' => 500,
            'discount_given' => 0,
            'product_vat' => 0,
            'amount' => 500,
            'on_wholesale_retail' => 0,
        ]);

        Sale::query()->create([
            'order_num' => 995102,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_name_override' => 'Buyer Without Oil',
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 100,
            'amount_paid' => 100,
            'completed_at' => now(),
            'archived' => 0,
        ]);

        $response = $this->getJson('/api/v1/sales?q='.urlencode($marker.' Cooking').'&per_page=50&date_field=placed');
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($withProduct->id));
        $this->assertFalse($ids->contains(
            Sale::query()->where('order_num', 995102)->value('id'),
        ));
    }

    public function test_sales_list_search_finds_orders_by_amount(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $uniqueTotal = 7788.25;
        $sale = Sale::query()->create([
            'order_num' => 995201,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'customer_name_override' => 'Amount Search Customer',
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => $uniqueTotal,
            'amount_paid' => $uniqueTotal,
            'completed_at' => now(),
            'archived' => 0,
        ]);

        $response = $this->getJson('/api/v1/sales?q='.urlencode((string) $uniqueTotal).'&per_page=50&date_field=placed');
        $response->assertOk();
        $this->assertTrue(collect($response->json('data'))->pluck('id')->contains($sale->id));
    }

    public function test_stage_queue_lists_old_orders_without_hot_window(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $oldUnpaid = Sale::query()->create([
            'order_num' => 993401,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'order_total' => 440,
            'amount_paid' => 0,
            'archived' => 0,
        ]);
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $oldUnpaid->id)->update([
            'created_at' => now()->subDays(45),
        ]);

        $response = $this->getJson('/api/v1/sales?filter[status]=unpaid&per_page=200');

        $response->assertOk()
            ->assertJsonPath('list_scope.applied', false)
            ->assertJsonPath('list_scope.skipped_for_stage_queue', true);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue(
            $ids->contains($oldUnpaid->id),
            'Unpaid stage queue must list older open-stage orders outside the default hot window.',
        );
    }

    public function test_stage_queue_still_respects_explicit_date_filter(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $oldUnpaid = Sale::query()->create([
            'order_num' => 993402,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'order_total' => 450,
            'amount_paid' => 0,
            'archived' => 0,
        ]);
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $oldUnpaid->id)->update([
            'created_at' => now()->subDays(45),
        ]);

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();
        $response = $this->getJson("/api/v1/sales?filter[status]=unpaid&from_date={$from}&to_date={$to}&date_field=placed&per_page=200");

        $response->assertOk()
            ->assertJsonPath('list_scope.applied', true);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($oldUnpaid->id));
    }

    public function test_payment_status_filter_is_not_narrowed_by_workflow_queue_permissions(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        \App\Services\Erp\PermissionMatrixService::ensure();

        $role = \App\Models\Role::create([
            'role_name' => 'Booked Queue Only '.uniqid(),
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $bookedViewId = (int) \App\Models\Permission::where('permission_code', 'sales.order_queue_booked.view')->value('id');
        $this->assertGreaterThan(0, $bookedViewId);

        \Illuminate\Support\Facades\DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $bookedViewId,
        ]);

        $viewer = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'booked_only_'.uniqid(),
            'password' => bcrypt('password'),
            'full_name' => 'Booked Queue Only',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        Sanctum::actingAs($viewer);

        $processedUnpaid = Sale::query()->create([
            'order_num' => 993403,
            'branch_id' => $viewer->branch_id,
            'organization_id' => $viewer->organization_id,
            'channel' => 'backend',
            'cashier_id' => $viewer->id,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'is_credit_sale' => true,
            'order_total' => 880,
            'amount_paid' => 0,
            'archived' => 0,
        ]);

        // Without payment_status filter, booked-only permission hides processed rows.
        $stageOnly = $this->getJson('/api/v1/sales?filter[status]=processed&per_page=200');
        $stageOnly->assertForbidden();

        // Amount-based unpaid queue must still surface the processed credit sale.
        $paymentQueue = $this->getJson('/api/v1/sales?filter[payment_status]=unpaid&per_page=200');
        $paymentQueue->assertOk();
        $this->assertTrue(
            collect($paymentQueue->json('data'))->pluck('id')->contains($processedUnpaid->id),
            'Payment-status unpaid list must not be restricted by workflow queue permissions.',
        );
    }

    public function test_unpaid_stage_queue_lists_unpaid_status_not_only_processed_unpaid(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $unpaidOnly = Sale::query()->create([
            'order_num' => 993501,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'order_total' => 1556,
            'amount_paid' => 0,
            'archived' => 0,
        ]);

        $processedUnpaid = Sale::query()->create([
            'order_num' => 993502,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'is_credit_sale' => true,
            'order_total' => 1644,
            'amount_paid' => 0,
            'archived' => 0,
        ]);

        $response = $this->getJson('/api/v1/sales?filter[status]=unpaid&per_page=200');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue(
            $ids->contains($unpaidOnly->id),
            'Unpaid stage queue must list orders whose workflow status is unpaid.',
        );
        $this->assertFalse(
            $ids->contains($processedUnpaid->id),
            'Unpaid stage queue must not pull processed+unpaid rows (those belong on Processed / payment queues).',
        );
    }

    public function test_payment_unpaid_queue_includes_workflow_unpaid_with_phantom_amount_paid(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        // Matches S1554-style rows: badge Unpaid, but amount_paid was inflated so
        // amount-only unpaid filters and outstanding_balance=1 previously hid them.
        $phantom = Sale::query()->create([
            'order_num' => 993554,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'paid',
            'order_total' => 4730,
            'amount_paid' => 4730,
            'archived' => 0,
        ]);
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $phantom->id)->update([
            'payment_status' => 'paid',
            'amount_paid' => 4730,
            'status' => 'unpaid',
        ]);

        $list = $this->getJson(
            '/api/v1/sales?filter[payment_status]=unpaid&outstanding_balance=1&exclude_statuses=cancelled,expired&per_page=200',
        )->assertOk();
        $ids = collect($list->json('data'))->pluck('id');
        $this->assertTrue(
            $ids->contains($phantom->id),
            'Unpaid Orders must list workflow-unpaid rows even when amount_paid looks settled.',
        );

        $summary = $this->getJson(
            '/api/v1/sales?q=993554&per_page=50&date_field=placed',
        )->assertOk()->json('summary');
        $this->assertSame(1, (int) ($summary['unpaid'] ?? 0), 'All Orders KPI must count workflow-unpaid as unpaid');
        $this->assertSame(0, (int) ($summary['paid'] ?? 0));
    }
}
