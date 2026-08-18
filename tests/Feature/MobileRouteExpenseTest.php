<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\Sale;
use App\Models\User;
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

        return Sale::create([
            'order_num' => 97001 + random_int(1, 8000),
            'branch_id' => $rep->branch_id ?? $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $rep->id,
            'customer_num' => $template->customer_num,
            'route_id' => $rep->assigned_route_id ?? $template->route_id,
            'status' => 'paid',
            'total_vat' => 0,
            'order_total' => $total,
            'payment_status' => 'paid',
            'amount_paid' => $total,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
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
