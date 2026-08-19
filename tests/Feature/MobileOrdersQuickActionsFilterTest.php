<?php

namespace Tests\Feature;

use App\Models\CustomerReturn;
use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MobileOrdersQuickActionsFilterTest extends TestCase
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
            $this->enableQuickActionCards($admin);
        }
    }

    public function test_pending_and_performed_returns_and_approve_honor_cashier_filter(): void
    {
        $repA = $this->makeMobileUser(['full_name' => 'Return Rep A']);
        $repB = $this->makeMobileUser(['full_name' => 'Return Rep B']);
        $saleA = $this->seedMobileSale($repA, 400);
        $saleB = $this->seedMobileSale($repB, 500);

        $pendingA = $this->makeReturn($saleA, $repA, 'pending');
        $pendingB = $this->makeReturn($saleB, $repB, 'pending');
        $doneA = $this->makeReturn($saleA, $repA, 'approved');
        $doneB = $this->makeReturn($saleB, $repB, 'approved');

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $pendingIds = collect($this->getJson('/api/v1/sales/mobile-orders/pending-returns?cashier_id='.$repA->id)
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
        $this->assertTrue($pendingIds->contains($pendingA->id));
        $this->assertFalse($pendingIds->contains($pendingB->id));

        $performedIds = collect($this->getJson('/api/v1/sales/mobile-orders/performed-returns?cashier_id='.$repA->id)
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
        $this->assertTrue($performedIds->contains($doneA->id));
        $this->assertFalse($performedIds->contains($doneB->id));

        $approve = $this->postJson('/api/v1/sales/mobile-orders/approve-returns', [
            'return_ids' => [$pendingB->id],
            'cashier_id' => $repA->id,
        ])->assertOk()->json();

        $this->assertSame(0, (int) $approve['approved_count']);
        $this->assertSame($pendingB->id, (int) ($approve['errors'][0]['id'] ?? 0));
        $this->assertSame('pending', $pendingB->fresh()->status);
    }

    public function test_reject_returns_honors_cashier_filter(): void
    {
        $repA = $this->makeMobileUser(['full_name' => 'Reject Rep A']);
        $repB = $this->makeMobileUser(['full_name' => 'Reject Rep B']);
        $saleA = $this->seedMobileSale($repA, 400);
        $saleB = $this->seedMobileSale($repB, 500);

        $pendingA = $this->makeReturn($saleA, $repA, 'pending');
        $pendingB = $this->makeReturn($saleB, $repB, 'pending');

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $reject = $this->postJson('/api/v1/sales/mobile-orders/reject-returns', [
            'return_ids' => [$pendingA->id, $pendingB->id],
            'cashier_id' => $repA->id,
            'reason' => 'Not a valid return',
        ])->assertOk()->json();

        $this->assertSame(1, (int) $reject['rejected_count']);
        $this->assertSame('rejected', $pendingA->fresh()->status);
        $this->assertSame('Not a valid return', $pendingA->fresh()->reject_reason);
        $this->assertSame('pending', $pendingB->fresh()->status);
    }

    public function test_mark_paid_honors_cashier_filter(): void
    {
        $repA = $this->makeMobileUser(['full_name' => 'Pay Rep A']);
        $repB = $this->makeMobileUser(['full_name' => 'Pay Rep B']);
        $saleA = $this->seedMobileSale($repA, 300, [
            'status' => 'booked',
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);
        $saleB = $this->seedMobileSale($repB, 350, [
            'status' => 'booked',
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $result = $this->postJson('/api/v1/sales/mobile-orders/mark-paid', [
            'sale_ids' => [$saleA->id, $saleB->id],
            'cashier_id' => $repA->id,
        ])->assertOk()->json();

        $this->assertSame(1, (int) $result['updated_count']);
        $this->assertSame($saleB->id, (int) ($result['errors'][0]['id'] ?? 0));
        $this->assertSame('paid', $saleA->fresh()->payment_status);
        $this->assertSame('unpaid', $saleB->fresh()->payment_status);
    }

    protected function enableQuickActionCards(User $user): void
    {
        $org = Organization::query()->findOrFail($user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'enable_mobile_orders' => true,
            'enable_mobile_orders_returns_card' => true,
            'enable_mobile_orders_payments_card' => true,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    protected function makeReturn(Sale $sale, User $rep, string $status): CustomerReturn
    {
        $seq = ((int) CustomerReturn::query()->max('return_seq')) + 1;
        $attrs = [
            'return_no' => 'RET-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT).'-'.substr(uniqid(), -4),
            'return_seq' => $seq,
            'organization_id' => $sale->organization_id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'status' => $status,
            'total_amount' => 10,
            'returned_by' => $rep->id,
            'approved_by' => $status === 'approved' ? $rep->id : null,
            'approved_at' => $status === 'approved' ? now() : null,
        ];
        if (Schema::hasColumn('customer_returns', 'return_kind')) {
            $attrs['return_kind'] = 'standard';
        }

        return CustomerReturn::create($attrs);
    }

    protected function seedMobileSale(User $rep, float $total, array $overrides = []): Sale
    {
        $template = Sale::query()->where('channel', 'mobile')->firstOrFail();

        return Sale::create(array_merge([
            'order_num' => 98001 + random_int(1, 8000),
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
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeMobileUser(array $overrides = []): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $routeId = (int) (\App\Models\RouteModel::query()->value('id') ?? 1);

        return User::create(array_merge([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'mobile_qa_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'Mobile Quick Action Rep',
            'is_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['mobile'],
            'mobile_order_scope' => 'route_only',
            'assigned_route_id' => $routeId,
            'is_active' => true,
        ], $overrides));
    }
}
