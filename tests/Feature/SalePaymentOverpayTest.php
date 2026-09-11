<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\PlatformSubscription;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SalePaymentOverpayTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::where('username', 'admin')->firstOrFail();
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

    public function test_sale_payment_rejects_amount_above_balance_due(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $sale = Sale::create([
            'order_num' => 880001,
            'branch_id' => $user->branch_id,
            'organization_id' => $user->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $user->id,
            'status' => 'booked',
            'total_vat' => 0,
            'order_total' => 10000,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'is_credit_sale' => 1,
            'stock_balanced' => 1,
        ]);

        $method = PaymentMethod::where('method_code', 'MPESA')->first()
            ?? PaymentMethod::where('method_code', 'CASH')->firstOrFail();

        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'payment_method_id' => $method->id,
            'amount' => 12000,
            'reference_number' => 'TESTOVERPAY001',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame(0.0, (float) $sale->fresh()->amount_paid);
    }

    public function test_sale_payment_partial_reduces_balance_due(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $sale = Sale::create([
            'order_num' => 880003,
            'branch_id' => $user->branch_id,
            'organization_id' => $user->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $user->id,
            'status' => 'booked',
            'total_vat' => 0,
            'order_total' => 10000,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'is_credit_sale' => 1,
            'stock_balanced' => 1,
        ]);

        $method = PaymentMethod::where('method_code', 'MPESA')->first()
            ?? PaymentMethod::where('method_code', 'CASH')->firstOrFail();

        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'payment_method_id' => $method->id,
            'amount' => 4000,
            'reference_number' => 'TESTPARTIAL001',
        ])->assertOk();

        $fresh = $sale->fresh();
        $this->assertEqualsWithDelta(4000.0, (float) $fresh->amount_paid, 0.01);
        $this->assertSame('partial', $fresh->payment_status);
        $this->assertEqualsWithDelta(6000.0, 10000.0 - (float) $fresh->amount_paid, 0.01);
    }

    public function test_sale_payment_accepts_exact_balance_due(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $sale = Sale::create([
            'order_num' => 880002,
            'branch_id' => $user->branch_id,
            'organization_id' => $user->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $user->id,
            'status' => 'booked',
            'total_vat' => 0,
            'order_total' => 10000,
            'payment_status' => 'partial',
            'amount_paid' => 2500,
            'is_credit_sale' => 1,
            'stock_balanced' => 1,
        ]);

        $method = PaymentMethod::where('method_code', 'MPESA')->first()
            ?? PaymentMethod::where('method_code', 'CASH')->firstOrFail();

        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'payment_method_id' => $method->id,
            'amount' => 7500,
            'reference_number' => 'TESTEXACT001',
        ])->assertOk();

        $fresh = $sale->fresh();
        $this->assertEqualsWithDelta(10000.0, (float) $fresh->amount_paid, 0.01);
        $this->assertSame('paid', $fresh->payment_status);
    }

    public function test_cashier_with_collect_payment_can_complete_partial_balance(): void
    {
        PermissionMatrixService::ensure();

        $admin = User::where('username', 'admin')->firstOrFail();
        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Partial Pay Cashier '.uniqid()],
            ['scope' => 'branch', 'is_active' => true],
        );

        $cashier = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'partial_pay_cashier_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Partial Pay Cashier',
            'access_scope' => 'branch',
            'login_channels' => ['backoffice', 'pos'],
            'is_active' => true,
        ]);

        $collectId = Permission::query()
            ->where('permission_code', 'sales.collect_payment.create')
            ->value('id');
        $this->assertNotNull($collectId);
        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'permission_id' => $collectId],
            [],
        );

        Sanctum::actingAs($cashier);

        $sale = Sale::create([
            'order_num' => 880004,
            'branch_id' => $cashier->branch_id,
            'organization_id' => $cashier->organization_id,
            'channel' => 'pos',
            'cashier_id' => $cashier->id,
            'status' => 'pending_payment',
            'total_vat' => 0,
            'order_total' => 1000,
            'payment_status' => 'partial',
            'amount_paid' => 400,
            'is_credit_sale' => 1,
            'stock_balanced' => 1,
        ]);

        $method = PaymentMethod::where('method_code', 'CASH')->firstOrFail();

        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'payment_method_id' => $method->id,
            'amount' => 600,
        ])->assertOk();

        $this->assertEqualsWithDelta(1000.0, (float) $sale->fresh()->amount_paid, 0.01);

        $this->postJson("/api/v1/sales/{$sale->id}/convert-to-unpaid")
            ->assertForbidden();
    }

    public function test_mobile_queue_viewer_can_index_sale_payments_without_payments_view(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        PermissionMatrixService::ensure();

        $role = Role::create([
            'role_name' => 'Mobile Queue Viewer',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $queueViewId = (int) Permission::where('permission_code', 'sales.order_queue_mobile.view')->value('id');
        $this->assertNotNull($queueViewId);

        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $queueViewId,
        ]);

        $viewer = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'mobile_queue_viewer',
            'password' => Hash::make('password'),
            'full_name' => 'Mobile Queue Viewer',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        Sanctum::actingAs($viewer);

        $sale = Sale::create([
            'order_num' => 880005,
            'branch_id' => $viewer->branch_id,
            'organization_id' => $viewer->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $viewer->id,
            'status' => 'booked',
            'total_vat' => 0,
            'order_total' => 5000,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'is_credit_sale' => 1,
            'stock_balanced' => 1,
        ]);

        $this->getJson("/api/v1/sale-payments?sale_ids={$sale->id}")
            ->assertOk();
    }

    public function test_split_payments_roll_back_when_a_method_is_invalid(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $sale = Sale::create([
            'order_num' => 880006,
            'branch_id' => $user->branch_id,
            'organization_id' => $user->organization_id,
            'channel' => 'backend',
            'cashier_id' => $user->id,
            'status' => 'unpaid',
            'total_vat' => 0,
            'order_total' => 10000,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'is_credit_sale' => 1,
            'stock_balanced' => 1,
        ]);

        $cash = PaymentMethod::where('method_code', 'CASH')->firstOrFail();

        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'payments' => [
                [
                    'payment_method_id' => $cash->id,
                    'amount' => 7000,
                ],
                [
                    'payment_method_id' => 999999001,
                    'amount' => 3000,
                ],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method_id']);

        $fresh = $sale->fresh();
        $this->assertSame(0.0, (float) $fresh->amount_paid);
        $this->assertSame('unpaid', $fresh->payment_status);
        $this->assertSame(0, $sale->payments()->count());
    }

    public function test_split_payments_covering_full_balance_succeed_when_partial_is_disabled(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);
        $this->setAllowCreditPayNow($user, false);

        $sale = Sale::create([
            'order_num' => 880007,
            'branch_id' => $user->branch_id,
            'organization_id' => $user->organization_id,
            'channel' => 'backend',
            'cashier_id' => $user->id,
            'status' => 'unpaid',
            'total_vat' => 0,
            'order_total' => 10000,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'is_credit_sale' => 1,
            'stock_balanced' => 1,
        ]);

        $cash = PaymentMethod::where('method_code', 'CASH')->firstOrFail();
        $mpesa = PaymentMethod::where('method_code', 'MPESA')->first()
            ?? PaymentMethod::where('id', '!=', $cash->id)->firstOrFail();

        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'payments' => [
                [
                    'payment_method_id' => $cash->id,
                    'amount' => 4000,
                ],
                [
                    'payment_method_id' => $mpesa->id,
                    'amount' => 6000,
                    'reference_number' => 'SPLITFULL001',
                ],
            ],
        ])->assertOk();

        $fresh = $sale->fresh();
        $this->assertEqualsWithDelta(10000.0, (float) $fresh->amount_paid, 0.01);
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertSame(2, $sale->payments()->count());
    }

    public function test_partial_split_batch_is_rejected_when_partial_is_disabled(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);
        $this->setAllowCreditPayNow($user, false);

        $sale = Sale::create([
            'order_num' => 880008,
            'branch_id' => $user->branch_id,
            'organization_id' => $user->organization_id,
            'channel' => 'backend',
            'cashier_id' => $user->id,
            'status' => 'unpaid',
            'total_vat' => 0,
            'order_total' => 10000,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'is_credit_sale' => 1,
            'stock_balanced' => 1,
        ]);

        $cash = PaymentMethod::where('method_code', 'CASH')->firstOrFail();
        $mpesa = PaymentMethod::where('method_code', 'MPESA')->first() ?? $cash;

        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'payments' => [
                [
                    'payment_method_id' => $cash->id,
                    'amount' => 4000,
                ],
                [
                    'payment_method_id' => $mpesa->id,
                    'amount' => 2000,
                ],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $fresh = $sale->fresh();
        $this->assertSame(0.0, (float) $fresh->amount_paid);
        $this->assertSame(0, $sale->payments()->count());
    }

    protected function setAllowCreditPayNow(User $user, bool $allowed): void
    {
        $org = $user->organization()->firstOrFail();
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'allow_credit_pay_now' => $allowed,
        ]);
        $org->update(['module_settings' => $settings]);
    }
}
