<?php

namespace Tests\Feature;

use App\Models\PlatformSubscription;
use App\Models\Sale;
use App\Models\User;
use App\Support\SalePaymentStatus;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SaleOrderSummaryPaymentBucketsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function seedLicense(User $user): void
    {
        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $user->organization_id],
            [
                'status' => 'active',
                'seat_count' => 5,
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'is_trial' => false,
            ],
        );
    }

    protected function seedSale(array $attrs): Sale
    {
        $sale = Sale::query()->create($attrs);
        // effective_sale_date is not fillable; pin placed date explicitly for list filters.
        if (! empty($attrs['effective_sale_date']) || ! empty($attrs['created_at'])) {
            $day = $attrs['effective_sale_date']
                ?? substr((string) $attrs['created_at'], 0, 10);
            DB::table('sales')->where('id', $sale->id)->update([
                'created_at' => $attrs['created_at'] ?? ($day.' 12:00:00'),
                'effective_sale_date' => $day,
            ]);
        }

        return $sale->fresh();
    }

    public function test_summary_uses_amounts_not_stale_payment_status(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->seedLicense($admin);
        Sanctum::actingAs($admin);

        $cashier = User::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'diana_bucket_'.substr(uniqid(), -8),
            'full_name' => 'Diana Bucket',
            'email' => 'diana_bucket_'.substr(uniqid(), -8).'@example.test',
            'password' => bcrypt('password'),
            'is_admin' => false,
            'access_scope' => 'org',
        ]);
        $cashierId = (int) $cashier->id;
        // Stay inside the 90-day sales list window.
        $day = now()->toDateString();

        // Stale "partial" but fully paid by amounts — must count as paid, not partial.
        $paidGhost = $this->seedSale([
            'order_num' => 880001,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $cashierId,
            'status' => 'paid',
            'payment_status' => 'paid',
            'order_total' => 1000,
            'amount_paid' => 1000,
            'created_at' => $day.' 10:00:00',
            'effective_sale_date' => $day,
        ]);
        // Bypass observer: leave a lying payment_status column in the DB.
        DB::table('sales')->where('id', $paidGhost->id)->update(['payment_status' => 'partial']);

        // True partial (some tender, balance remaining) — even if label is wrong elsewhere.
        $truePartial = $this->seedSale([
            'order_num' => 880002,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $cashierId,
            'status' => 'pending_payment',
            'payment_status' => 'partial',
            'order_total' => 1000,
            'amount_paid' => 250,
            'created_at' => $day.' 11:00:00',
            'effective_sale_date' => $day,
        ]);
        DB::table('sales')->where('id', $truePartial->id)->update(['payment_status' => 'unpaid']);

        // True unpaid.
        $this->seedSale([
            'order_num' => 880003,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $cashierId,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'order_total' => 500,
            'amount_paid' => 0,
            'created_at' => $day.' 12:00:00',
            'effective_sale_date' => $day,
        ]);

        // Cancelled with leftover partial label — must not inflate unpaid/partial cards.
        $cancelledGhost = $this->seedSale([
            'order_num' => 880004,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $cashierId,
            'status' => 'cancelled',
            'payment_status' => 'unpaid',
            'order_total' => 800,
            'amount_paid' => 100,
            'created_at' => $day.' 13:00:00',
            'effective_sale_date' => $day,
        ]);
        DB::table('sales')->where('id', $cancelledGhost->id)->update(['payment_status' => 'partial']);

        $res = $this->getJson('/api/v1/sales?'.http_build_query([
            'from_date' => $day,
            'to_date' => $day,
            'date_field' => 'placed',
            'cashier_id' => $cashierId,
            'exclude_status' => 'held',
            'per_page' => 50,
        ]))->assertOk();

        $summary = $res->json('summary');
        $this->assertSame(1, (int) $summary['unpaid'], 'expected one true unpaid');
        $this->assertSame(1, (int) $summary['partial'], 'expected one true partial');
        $this->assertSame(1, (int) $summary['paid'], 'stale partial label with full pay counts as paid');
        $this->assertSame(1, (int) $summary['cancelled']);

        // Partial filter must find the real partial by amounts (not stale payment_status).
        $partialList = $this->getJson('/api/v1/sales?'.http_build_query([
            'from_date' => $day,
            'to_date' => $day,
            'date_field' => 'placed',
            'cashier_id' => $cashierId,
            'filter' => ['payment_status' => 'partial'],
            'per_page' => 50,
        ]))->assertOk();

        $partialIds = collect($partialList->json('data'))->pluck('order_num')->map(fn ($n) => (int) $n)->all();
        $this->assertContains(880002, $partialIds);
        $this->assertNotContains(880001, $partialIds);
        $this->assertNotContains(880003, $partialIds);
        $this->assertNotContains(880004, $partialIds);
        $this->assertSame(1, (int) $partialList->json('summary.partial'));
        $this->assertSame(0, (int) $partialList->json('summary.unpaid'));
    }

    public function test_sale_payment_status_helper_derives_buckets(): void
    {
        $this->assertSame('paid', SalePaymentStatus::derive(100, 100));
        $this->assertSame('unpaid', SalePaymentStatus::derive(100, 0));
        $this->assertSame('partial', SalePaymentStatus::derive(100, 40));
        $this->assertSame('partial', SalePaymentStatus::normalizeLabel('partially_paid'));
        $this->assertSame('unpaid', SalePaymentStatus::resolve('cancelled', 100, 40));
        $this->assertSame('paid', SalePaymentStatus::resolve('paid', 100, 100));
    }
}
