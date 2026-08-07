<?php

namespace Tests\Unit;

use App\Models\Sale;
use App\Models\Till;
use App\Models\TillFloatSession;
use App\Models\User;
use App\Services\Sales\PosDailyOrderNumberAllocator;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PosDailyOrderNumberAllocatorTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_allocates_sequential_numbers_per_cashier_per_day(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $allocator = app(PosDailyOrderNumberAllocator::class);
        $day = now()->toDateString();

        $first = $allocator->allocateForCheckout((int) $admin->organization_id, (int) $admin->id, $day);
        $this->assertSame(1, $first['pos_order_num']);
        $this->assertSame($day, $first['pos_order_date']);

        Sale::query()->create([
            'order_num' => 880001,
            'pos_order_num' => $first['pos_order_num'],
            'pos_order_date' => $first['pos_order_date'],
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 10,
            'amount_paid' => 10,
        ]);

        $second = $allocator->allocateForCheckout((int) $admin->organization_id, (int) $admin->id, $day);
        $this->assertSame(2, $second['pos_order_num']);
    }

    public function test_other_cashier_starts_at_one_same_day(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $other = User::query()
            ->where('organization_id', $admin->organization_id)
            ->where('id', '!=', $admin->id)
            ->first();
        if (! $other) {
            $this->markTestSkipped('Need a second user in the seed org.');
        }

        $allocator = app(PosDailyOrderNumberAllocator::class);
        $day = now()->toDateString();

        Sale::query()->create([
            'order_num' => 880002,
            'pos_order_num' => 5,
            'pos_order_date' => $day,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 10,
            'amount_paid' => 10,
        ]);

        $forOther = $allocator->allocateForCheckout((int) $admin->organization_id, (int) $other->id, $day);
        $this->assertSame(1, $forOther['pos_order_num']);
    }

    public function test_take_from_sale_clears_source_and_returns_ticket(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $day = now()->toDateString();
        $sale = Sale::query()->create([
            'order_num' => 880003,
            'pos_order_num' => 7,
            'pos_order_date' => $day,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 10,
            'amount_paid' => 10,
        ]);

        $taken = app(PosDailyOrderNumberAllocator::class)->takeFromSale($sale->fresh());
        $this->assertSame(7, $taken['pos_order_num']);
        $this->assertSame($day, $taken['pos_order_date']);
        $sale->refresh();
        $this->assertNull($sale->pos_order_num);
        $this->assertNull($sale->pos_order_date);
    }

    public function test_reserve_block_does_not_advance_cash_sales_sequence(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $allocator = app(PosDailyOrderNumberAllocator::class);
        $day = now()->toDateString();

        Sale::query()->create([
            'order_num' => 880030,
            'pos_order_num' => 6,
            'pos_order_date' => $day,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 10,
            'amount_paid' => 10,
        ]);

        // Old bug: reserving 20 org slots advanced watermark to 26 → next Cash Sales #27.
        $block = $allocator->reserveBlockForCashier(
            (int) $admin->organization_id,
            (int) $admin->id,
            20,
            $day,
        );
        $this->assertSame([], $block['tickets']);

        $next = $allocator->allocateForCheckout((int) $admin->organization_id, (int) $admin->id, $day);
        $this->assertSame(7, $next['pos_order_num'], 'Cash Sales # must continue 1,2,3… from sales, not jump over a reserve block');
    }

    public function test_peek_after_sales_is_sale_max_plus_one(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $allocator = app(PosDailyOrderNumberAllocator::class);
        $day = now()->toDateString();

        Sale::query()->create([
            'order_num' => 880031,
            'pos_order_num' => 3,
            'pos_order_date' => $day,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 10,
            'amount_paid' => 10,
        ]);

        $peek = $allocator->peekNextForCashier((int) $admin->organization_id, (int) $admin->id, $day);
        $this->assertSame(4, $peek['pos_order_num']);
    }

    public function test_claim_fails_when_taken_and_allocate_returns_next_free(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $allocator = app(PosDailyOrderNumberAllocator::class);
        $day = now()->toDateString();

        Sale::query()->create([
            'order_num' => 880041,
            'pos_order_num' => 274,
            'pos_order_date' => $day,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 10,
            'amount_paid' => 10,
        ]);

        $claimed = $allocator->claimPrintedTicketForCheckout(
            (int) $admin->organization_id,
            (int) $admin->id,
            274,
            $day,
        );
        $this->assertFalse($claimed, 'Taken Cash Sales # must not be reclaimable');

        $next = $allocator->allocateForCheckout((int) $admin->organization_id, (int) $admin->id, $day);
        $this->assertSame(275, $next['pos_order_num'], 'Offline sync should bump to next free Cash Sales #');
    }

    public function test_held_sales_do_not_advance_but_cancelled_do(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $allocator = app(PosDailyOrderNumberAllocator::class);
        $day = now()->toDateString();

        Sale::query()->create([
            'order_num' => 880032,
            'pos_order_num' => 2,
            'pos_order_date' => $day,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 10,
            'amount_paid' => 10,
        ]);

        Sale::query()->create([
            'order_num' => 880033,
            'pos_order_num' => 99,
            'pos_order_date' => $day,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'held',
            'payment_status' => 'unpaid',
            'order_total' => 10,
            'amount_paid' => 0,
        ]);

        Sale::query()->create([
            'order_num' => 880034,
            'pos_order_num' => 100,
            'pos_order_date' => $day,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'cancelled',
            'payment_status' => 'unpaid',
            'order_total' => 10,
            'amount_paid' => 0,
        ]);

        $peek = $allocator->peekNextForCashier((int) $admin->organization_id, (int) $admin->id, $day);
        // Held #99 ignored; cancelled #100 consumed → next is 101.
        $this->assertSame(101, $peek['pos_order_num']);
    }

    public function test_cancelled_cash_sales_number_is_skipped_not_reused(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $allocator = app(PosDailyOrderNumberAllocator::class);
        $day = now()->toDateString();

        Sale::query()->create([
            'order_num' => 880050,
            'pos_order_num' => 274,
            'pos_order_date' => $day,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'cancelled',
            'payment_status' => 'unpaid',
            'order_total' => 10,
            'amount_paid' => 0,
        ]);

        $this->assertFalse(
            $allocator->claimPrintedTicketForCheckout(
                (int) $admin->organization_id,
                (int) $admin->id,
                274,
                $day,
            ),
            'Cancelled Cash Sales #274 must not be reclaimable',
        );

        $next = $allocator->allocateForCheckout((int) $admin->organization_id, (int) $admin->id, $day);
        $this->assertSame(275, $next['pos_order_num'], 'After cancelled #274, next Cash Sales # must be 275');

        $peek = $allocator->peekNextForCashier((int) $admin->organization_id, (int) $admin->id, $day);
        $this->assertSame(275, $peek['pos_order_num']);
    }

    public function test_claim_reserved_for_checkout_rejects_duplicate_ticket(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $allocator = app(PosDailyOrderNumberAllocator::class);
        $day = now()->toDateString();

        $this->assertTrue($allocator->claimReservedForCheckout(
            (int) $admin->organization_id,
            (int) $admin->id,
            2,
            $day,
        ));

        Sale::query()->create([
            'order_num' => 880010,
            'pos_order_num' => 2,
            'pos_order_date' => $day,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 10,
            'amount_paid' => 10,
        ]);

        $this->assertFalse($allocator->claimReservedForCheckout(
            (int) $admin->organization_id,
            (int) $admin->id,
            2,
            $day,
        ));
    }

    public function test_new_float_session_same_day_starts_cash_sales_at_one(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $till = Till::query()->firstOrFail();
        $allocator = app(PosDailyOrderNumberAllocator::class);
        $day = now()->toDateString();
        $orgId = (int) $admin->organization_id;
        $cashierId = (int) $admin->id;

        $sessionA = TillFloatSession::create([
            'organization_id' => $orgId,
            'till_id' => $till->id,
            'branch_id' => $admin->branch_id,
            'cashier_id' => $cashierId,
            'session_date' => $day,
            'working_amount' => 1000,
            'float_breakdown' => [],
            'status' => 'closed',
            'opened_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
        ]);

        Sale::query()->create([
            'order_num' => 880060,
            'pos_order_num' => 5,
            'pos_order_date' => $day,
            'float_session_id' => $sessionA->id,
            'branch_id' => $admin->branch_id,
            'organization_id' => $orgId,
            'channel' => 'pos',
            'cashier_id' => $cashierId,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 10,
            'amount_paid' => 10,
        ]);

        $peekA = $allocator->peekNextForCashier($orgId, $cashierId, $day, (int) $sessionA->id);
        $this->assertSame(6, $peekA['pos_order_num']);

        // Z closed session A; same calendar day, new float session B must restart at #1.
        $sessionB = TillFloatSession::create([
            'organization_id' => $orgId,
            'till_id' => $till->id,
            'branch_id' => $admin->branch_id,
            'cashier_id' => $cashierId,
            'session_date' => $day,
            'working_amount' => 1000,
            'float_breakdown' => [],
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $peekB = $allocator->peekNextForCashier($orgId, $cashierId, $day, (int) $sessionB->id);
        $this->assertSame(1, $peekB['pos_order_num'], 'New float session same day must peek Cash Sales #1');

        $allocated = $allocator->allocateForCheckout($orgId, $cashierId, $day, (int) $sessionB->id);
        $this->assertSame(1, $allocated['pos_order_num'], 'New float session same day must allocate Cash Sales #1');
    }
}
