<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\MobileRouteExpenseService;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MobileRouteExpenseTest extends TestCase
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
            $this->enableExpensesCard($admin);
        }
    }

    public function test_rep_can_submit_expense_and_manager_approval_deducts_daily_sales(): void
    {
        $rep = $this->makeMobileUser();
        $today = now()->toDateString();
        $this->seedMobileSale($rep, 1000);

        $token = $this->loginMobile($rep);

        $dashboard = $this->withToken($token)
            ->getJson('/api/v1/mobile/dashboard')
            ->assertOk()
            ->json('summary');
        $this->assertEqualsWithDelta(1000.0, (float) $dashboard['orderTotals'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) ($dashboard['expenseTotals'] ?? 0), 0.001);

        $created = $this->withToken($token)
            ->postJson('/api/v1/mobile/expenses', [
                'description' => 'Fuel for route',
                'expense_amount' => 150,
                'expense_date' => $today,
            ])
            ->assertCreated()
            ->json();

        $this->assertSame('pending', $created['status']);
        $this->assertEqualsWithDelta(150.0, (float) $created['expense_amount'], 0.001);

        $this->assertEqualsWithDelta(
            1000.0,
            (float) $this->withToken($token)->getJson('/api/v1/mobile/dashboard')->json('summary.orderTotals'),
            0.001,
        );

        $warmedList = $this->withToken($token)
            ->getJson('/api/v1/mobile/orders')
            ->assertOk();
        $this->assertEqualsWithDelta(1000.0, (float) $warmedList->json('meta.summary.order_total'), 0.001);
        $this->assertEqualsWithDelta(0.0, (float) ($warmedList->json('meta.summary.expense_total') ?? 0), 0.001);

        $admin = User::where('username', 'admin')->firstOrFail();
        $this->withoutToken();
        Sanctum::actingAs($admin);

        $pending = $this->getJson('/api/v1/sales/mobile-orders/pending-expenses')
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($pending);
        $expenseId = (int) collect($pending)->firstWhere('id', $created['id'])['id'];

        $this->postJson('/api/v1/sales/mobile-orders/approve-expenses', [
            'expense_ids' => [$expenseId],
        ])->assertOk()->assertJsonPath('approved_count', 1);

        $this->app['auth']->forgetGuards();
        $after = $this->withToken($token)
            ->getJson('/api/v1/mobile/dashboard')
            ->assertOk()
            ->json('summary');
        $this->assertEqualsWithDelta(850.0, (float) $after['orderTotals'], 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $after['expenseTotals'], 0.001);

        $todayList = $this->withToken($token)
            ->getJson('/api/v1/mobile/orders')
            ->assertOk();
        $this->assertEqualsWithDelta(1000.0, (float) $todayList->json('meta.summary.gross_total'), 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $todayList->json('meta.summary.expense_total'), 0.001);
        $this->assertEqualsWithDelta(850.0, (float) $todayList->json('meta.summary.order_total'), 0.001);

        $datedList = $this->withToken($token)
            ->getJson('/api/v1/mobile/orders?from_date='.$today.'&to_date='.$today)
            ->assertOk();
        $this->assertEqualsWithDelta(850.0, (float) $datedList->json('meta.summary.order_total'), 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $datedList->json('meta.summary.expense_total'), 0.001);

        $reconciliation = $this->withToken($token)
            ->getJson('/api/v1/mobile/reconciliation')
            ->assertOk()
            ->json();
        $todayRow = collect($reconciliation['daily_sales'] ?? [])
            ->firstWhere('create_date', $today);
        $this->assertNotNull($todayRow);
        $this->assertEqualsWithDelta(150.0, (float) $todayRow['expense_amount'], 0.001);
        $this->assertEqualsWithDelta(850.0, (float) $todayRow['total_amount'], 0.001);
    }

    public function test_web_mobile_orders_summary_deducts_approved_expenses_for_cashier_and_dates(): void
    {
        $rep = $this->makeMobileUser(['full_name' => 'Chege']);
        $from = now()->subDay()->startOfDay();
        $to = now()->startOfDay();
        $this->seedMobileSale($rep, 1000, $from);
        $this->seedMobileSale($rep, 500, $to);

        $token = $this->loginMobile($rep);
        $created = $this->withToken($token)
            ->postJson('/api/v1/mobile/expenses', [
                'description' => 'Fuel',
                'expense_amount' => 200,
                'expense_date' => $from->toDateString(),
            ])
            ->assertCreated()
            ->json();

        $admin = User::where('username', 'admin')->firstOrFail();
        $this->withoutToken();
        Sanctum::actingAs($admin);

        $before = $this->getJson(
            '/api/v1/sales?order_source=mobile&cashier_id='.$rep->id
            .'&from_date='.$from->toDateString()
            .'&to_date='.$to->toDateString()
            .'&date_field=placed&exclude_statuses=cancelled,expired&per_page=25',
        )->assertOk()->json('summary');
        $this->assertEqualsWithDelta(1500.0, (float) $before['revenue'], 0.001);

        $this->postJson('/api/v1/sales/mobile-orders/approve-expenses', [
            'expense_ids' => [$created['id']],
        ])->assertOk()->assertJsonPath('approved_count', 1);

        $after = $this->getJson(
            '/api/v1/sales?order_source=mobile&cashier_id='.$rep->id
            .'&from_date='.$from->toDateString()
            .'&to_date='.$to->toDateString()
            .'&date_field=placed&exclude_statuses=cancelled,expired&per_page=25',
        )->assertOk()->json('summary');

        $this->assertEqualsWithDelta(1500.0, (float) $after['gross_revenue'], 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $after['expense_total'], 0.001);
        $this->assertEqualsWithDelta(1300.0, (float) $after['revenue'], 0.001);
    }

    public function test_web_mobile_orders_summary_honors_filter_cashier_id_for_expense_deduction(): void
    {
        $repA = $this->makeMobileUser(['full_name' => 'Jacob A']);
        $repB = $this->makeMobileUser(['full_name' => 'Jacob B']);
        $day = now()->subDay()->startOfDay();
        $this->seedMobileSale($repA, 1000, $day);
        $this->seedMobileSale($repB, 500, $day);

        $expenses = app(MobileRouteExpenseService::class);
        $expenseA = $expenses->createForRep($repA, [
            'description' => 'Fuel A',
            'expense_amount' => 200,
            'expense_date' => $day->toDateString(),
        ]);
        $expenseB = $expenses->createForRep($repB, [
            'description' => 'Fuel B',
            'expense_amount' => 200,
            'expense_date' => $day->toDateString(),
        ]);

        $admin = User::where('username', 'admin')->firstOrFail();
        $this->withoutToken();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/sales/mobile-orders/approve-expenses', [
            'expense_ids' => [(int) $expenseA['id'], (int) $expenseB['id']],
        ])->assertOk()->assertJsonPath('approved_count', 2);

        // Reproduce the ERP Mobile Orders page: User filter is sent as filter[cashier_id].
        $query = http_build_query([
            'order_source' => 'mobile',
            'filter' => ['cashier_id' => $repA->id],
            'from_date' => $day->toDateString(),
            'to_date' => $day->toDateString(),
            'date_field' => 'placed',
            'exclude_statuses' => 'cancelled,expired',
            'per_page' => 25,
        ]);
        $summary = $this->getJson('/api/v1/sales?'.$query)
            ->assertOk()
            ->json('summary');

        $this->assertEqualsWithDelta(1000.0, (float) ($summary['gross_revenue'] ?? $summary['revenue'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(200.0, (float) ($summary['expense_total'] ?? 0), 0.001,
            'Must deduct only Jacob A\'s 200 expense, not both reps (400).');
        $this->assertEqualsWithDelta(800.0, (float) $summary['revenue'], 0.001);

        $this->app['auth']->forgetGuards();
    }

    public function test_approved_expense_updates_past_day_order_list_totals(): void
    {
        $rep = $this->makeMobileUser();
        $yesterday = now()->subDay();
        $date = $yesterday->toDateString();
        $this->seedMobileSale($rep, 1000, $yesterday);

        $token = $this->loginMobile($rep);

        $before = $this->withToken($token)
            ->getJson('/api/v1/mobile/orders?from_date='.$date.'&to_date='.$date)
            ->assertOk();
        $this->assertEqualsWithDelta(1000.0, (float) $before->json('meta.summary.order_total'), 0.001);

        $created = $this->withToken($token)
            ->postJson('/api/v1/mobile/expenses', [
                'description' => 'Parking',
                'expense_amount' => 150,
                'expense_date' => $date,
            ])
            ->assertCreated()
            ->json();

        $admin = User::where('username', 'admin')->firstOrFail();
        $this->withoutToken();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/sales/mobile-orders/approve-expenses', [
            'expense_ids' => [$created['id']],
        ])->assertOk()->assertJsonPath('approved_count', 1);

        $this->app['auth']->forgetGuards();
        $after = $this->withToken($token)
            ->getJson('/api/v1/mobile/orders?from_date='.$date.'&to_date='.$date)
            ->assertOk();
        $this->assertEqualsWithDelta(1000.0, (float) $after->json('meta.summary.gross_total'), 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $after->json('meta.summary.expense_total'), 0.001);
        $this->assertEqualsWithDelta(850.0, (float) $after->json('meta.summary.order_total'), 0.001);
    }

    public function test_past_day_warmed_cache_without_summary_still_deducts_approved_expenses(): void
    {
        $rep = $this->makeMobileUser(['full_name' => 'Cache Exp Rep']);
        $yesterday = now()->subDay()->startOfDay();
        $date = $yesterday->toDateString();
        $this->seedMobileSale($rep, 1000, $yesterday);

        $expenses = app(MobileRouteExpenseService::class);
        $created = $expenses->createForRep($rep, [
            'description' => 'Fuel',
            'expense_amount' => 200,
            'expense_date' => $date,
        ]);

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/sales/mobile-orders/approve-expenses', [
            'expense_ids' => [(int) $created['id']],
        ])->assertOk()->assertJsonPath('approved_count', 1);

        // Simulate a warmed past-day list that only stored order rows (no KPI summary).
        $org = \App\Models\Organization::query()->findOrFail($rep->organization_id);
        app(\App\Services\Cache\CompletedSalesCacheService::class)
            ->warmOrganizationDate($org, $yesterday);

        $this->app['auth']->forgetGuards();
        $token = $this->loginMobile($rep);
        $summary = $this->withToken($token)
            ->getJson('/api/v1/mobile/orders?from_date='.$date.'&to_date='.$date)
            ->assertOk()
            ->json('meta.summary');

        $this->assertEqualsWithDelta(1000.0, (float) ($summary['gross_total'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(200.0, (float) ($summary['expense_total'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(800.0, (float) ($summary['order_total'] ?? 0), 0.001);
    }

    public function test_expenses_are_blocked_when_platform_flag_is_off(): void
    {
        $rep = $this->makeMobileUser();
        $this->setExpensesCard($rep, false);
        $token = $this->loginMobile($rep);

        $this->withToken($token)
            ->postJson('/api/v1/mobile/expenses', [
                'description' => 'Lunch',
                'expense_amount' => 50,
            ])
            ->assertStatus(422);
    }

    public function test_pending_and_performed_expenses_and_approve_honor_cashier_filter(): void
    {
        $repA = $this->makeMobileUser(['full_name' => 'Expense Rep A']);
        $repB = $this->makeMobileUser(['full_name' => 'Expense Rep B']);
        $expenses = app(MobileRouteExpenseService::class);
        $expenseA = $expenses->createForRep($repA, [
            'description' => 'Fuel A',
            'expense_amount' => 100,
        ]);
        $expenseB = $expenses->createForRep($repB, [
            'description' => 'Fuel B',
            'expense_amount' => 80,
        ]);

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $pendingA = collect($this->getJson('/api/v1/sales/mobile-orders/pending-expenses?cashier_id='.$repA->id)
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
        $this->assertTrue($pendingA->contains((int) $expenseA['id']));
        $this->assertFalse($pendingA->contains((int) $expenseB['id']));

        $approve = $this->postJson('/api/v1/sales/mobile-orders/approve-expenses', [
            'expense_ids' => [(int) $expenseA['id'], (int) $expenseB['id']],
            'cashier_id' => $repA->id,
        ])->assertOk()->json();

        $this->assertSame(1, (int) $approve['approved_count']);
        $this->assertSame((int) $expenseB['id'], (int) ($approve['errors'][0]['id'] ?? 0));

        $this->postJson('/api/v1/sales/mobile-orders/approve-expenses', [
            'expense_ids' => [(int) $expenseB['id']],
            'cashier_id' => $repB->id,
        ])->assertOk()->assertJsonPath('approved_count', 1);

        $performedA = collect($this->getJson('/api/v1/sales/mobile-orders/performed-expenses?cashier_id='.$repA->id)
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
        $this->assertTrue($performedA->contains((int) $expenseA['id']));
        $this->assertFalse($performedA->contains((int) $expenseB['id']));
    }

    public function test_reject_expenses_honors_cashier_filter(): void
    {
        $repA = $this->makeMobileUser(['full_name' => 'Reject Exp A']);
        $repB = $this->makeMobileUser(['full_name' => 'Reject Exp B']);
        $expenses = app(MobileRouteExpenseService::class);
        $expenseA = $expenses->createForRep($repA, [
            'description' => 'Parking A',
            'expense_amount' => 40,
        ]);
        $expenseB = $expenses->createForRep($repB, [
            'description' => 'Parking B',
            'expense_amount' => 60,
        ]);

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $reject = $this->postJson('/api/v1/sales/mobile-orders/reject-expenses', [
            'expense_ids' => [(int) $expenseA['id'], (int) $expenseB['id']],
            'cashier_id' => $repA->id,
            'reason' => 'Not a route cost',
        ])->assertOk()->json();

        $this->assertSame(1, (int) $reject['rejected_count']);
        $this->assertSame(
            'rejected',
            \App\Models\MobileRouteExpense::query()->findOrFail($expenseA['id'])->status,
        );
        $this->assertSame(
            'pending',
            \App\Models\MobileRouteExpense::query()->findOrFail($expenseB['id'])->status,
        );
    }

    public function test_mobile_expense_appears_on_erp_expenses_list_and_reject_removes_it(): void
    {
        $rep = $this->makeMobileUser(['full_name' => 'Ledger Sync Rep']);
        $token = $this->loginMobile($rep);
        $today = now()->toDateString();

        $created = $this->withToken($token)
            ->postJson('/api/v1/mobile/expenses', [
                'description' => 'Route lunch',
                'expense_amount' => 275,
                'expense_date' => $today,
            ])
            ->assertCreated()
            ->json();

        $ledger = \App\Models\Expense::query()
            ->where('mobile_route_expense_id', (int) $created['id'])
            ->first();
        $this->assertNotNull($ledger);
        $this->assertEqualsWithDelta(275.0, (float) $ledger->expense_amount, 0.001);
        $this->assertSame('MOBILE-'.$created['id'], $ledger->invoice_no);

        $admin = User::where('username', 'admin')->firstOrFail();
        $this->withoutToken();
        Sanctum::actingAs($admin);

        $list = $this->getJson('/api/v1/expenses?from_date='.$today.'&to_date='.$today.'&per_page=100')
            ->assertOk()
            ->json('data');
        $this->assertTrue(
            collect($list)->contains(fn ($row) => (int) ($row['id'] ?? 0) === (int) $ledger->id),
            'Mobile expense should appear on GET /api/v1/expenses',
        );

        $this->postJson('/api/v1/sales/mobile-orders/reject-expenses', [
            'expense_ids' => [(int) $created['id']],
            'reason' => 'Duplicate claim',
        ])->assertOk()->assertJsonPath('rejected_count', 1);

        $this->assertTrue(
            \App\Models\Expense::withTrashed()
                ->where('mobile_route_expense_id', (int) $created['id'])
                ->whereNotNull('deleted_at')
                ->exists(),
        );

        $afterReject = $this->getJson('/api/v1/expenses?from_date='.$today.'&to_date='.$today.'&per_page=100')
            ->assertOk()
            ->json('data');
        $this->assertFalse(
            collect($afterReject)->contains(fn ($row) => (int) ($row['id'] ?? 0) === (int) $ledger->id),
            'Rejected mobile expense should leave the active ERP expenses list',
        );
    }

    public function test_deleting_erp_expense_removes_it_from_list_and_rejects_mobile_row(): void
    {
        $rep = $this->makeMobileUser(['full_name' => 'Delete Ledger Rep']);
        $token = $this->loginMobile($rep);
        $today = now()->toDateString();

        $created = $this->withToken($token)
            ->postJson('/api/v1/mobile/expenses', [
                'description' => 'Toll fee',
                'expense_amount' => 120,
                'expense_date' => $today,
            ])
            ->assertCreated()
            ->json();

        $ledger = \App\Models\Expense::query()
            ->where('mobile_route_expense_id', (int) $created['id'])
            ->firstOrFail();

        $admin = User::where('username', 'admin')->firstOrFail();
        $this->withoutToken();
        Sanctum::actingAs($admin);

        $this->deleteJson('/api/v1/expenses/'.$ledger->id)->assertNoContent();

        $this->assertNotNull($ledger->fresh()->deleted_at);
        $this->assertSame(
            'rejected',
            \App\Models\MobileRouteExpense::query()->findOrFail($created['id'])->status,
        );

        $list = $this->getJson('/api/v1/expenses?from_date='.$today.'&to_date='.$today.'&per_page=100')
            ->assertOk()
            ->json('data');
        $this->assertFalse(
            collect($list)->contains(fn ($row) => (int) ($row['id'] ?? 0) === (int) $ledger->id),
            'Deleted expense must leave the active ERP expenses list',
        );
    }

    protected function enableExpensesCard(User $user): void
    {
        $this->setExpensesCard($user, true);
    }

    protected function setExpensesCard(User $user, bool $enabled): void
    {
        $org = Organization::query()->findOrFail($user->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['sales.mobile'] = true;
        $modules['sales.backend'] = true;
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'enable_mobile_orders' => true,
            'enable_mobile_orders_expenses_card' => $enabled,
        ]);
        $org->update([
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ]);
    }

    protected function seedMobileSale(User $rep, float $total, $at = null): Sale
    {
        $template = Sale::query()->where('channel', 'mobile')->firstOrFail();
        $at = $at ? \Carbon\Carbon::parse($at) : now();

        $sale = Sale::create([
            'order_num' => 97001 + random_int(1, 8000),
            'branch_id' => $rep->branch_id ?? $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'order_source' => 'mobile',
            'cashier_id' => $rep->id,
            'customer_num' => $template->customer_num,
            'route_id' => $rep->assigned_route_id ?? $template->route_id,
            'status' => 'paid',
            'total_vat' => 0,
            'order_total' => $total,
            'payment_status' => 'paid',
            'amount_paid' => $total,
        ]);

        // created_at is not fillable — force it so date-scoped summaries match the seed day.
        $sale->forceFill(['created_at' => $at])->saveQuietly();

        return $sale->fresh();
    }

    protected function makeMobileUser(array $overrides = []): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $routeId = (int) (\App\Models\RouteModel::query()->value('id') ?? 1);

        return User::create(array_merge([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'mobile_exp_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'Mobile Expense Rep',
            'is_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['mobile'],
            'mobile_order_scope' => 'route_only',
            'assigned_route_id' => $routeId,
            'is_active' => true,
        ], $overrides));
    }

    protected function loginMobile(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'MOBILE_EXP_'.uniqid(),
            'login_channel' => 'mobile',
        ])->assertOk()->json('token');
    }
}
