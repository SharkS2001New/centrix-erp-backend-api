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

    public function test_sales_list_search_expands_to_one_month_window(): void
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

        // Free-text search stays inside the platform search window (~1 month).
        $today = now()->toDateString();
        $response = $this->getJson("/api/v1/sales?q=Within%20Month&from_date={$today}&to_date={$today}&per_page=50&date_field=placed");

        $response->assertOk()
            ->assertJsonPath('list_scope.skipped_for_search', false)
            ->assertJsonPath('list_scope.search_window', true);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($withinMonth->id));

        $oldName = $this->getJson("/api/v1/sales?q=Too%20Old&from_date={$today}&to_date={$today}&per_page=50&date_field=placed");
        $oldIds = collect($oldName->json('data'))->pluck('id');
        $this->assertFalse($oldIds->contains($tooOld->id));
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
}
