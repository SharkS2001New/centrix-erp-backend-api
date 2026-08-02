<?php

namespace Tests\Unit;

use App\Models\Sale;
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
}
