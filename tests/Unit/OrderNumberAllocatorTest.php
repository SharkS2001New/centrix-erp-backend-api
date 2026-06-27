<?php

namespace Tests\Unit;

use App\Services\Sales\OrderNumberAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderNumberAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_order_number_is_one(): void
    {
        $allocator = app(OrderNumberAllocator::class);

        $this->assertSame(1, $allocator->nextForOrganization(99));
    }

    public function test_legacy_imported_order_numbers_are_ignored(): void
    {
        \DB::table('sales')->insert([
            'order_num' => OrderNumberAllocator::LEGACY_IMPORTED_ORDER_NUM_MIN + 42,
            'branch_id' => 1,
            'organization_id' => 99,
            'channel' => 'pos',
            'payment_status' => 'paid',
            'amount_paid' => 0,
            'cashier_id' => 1,
            'status' => 'completed',
            'total_vat' => 0,
            'order_total' => 0,
            'stock_balanced' => 1,
        ]);

        $allocator = app(OrderNumberAllocator::class);

        $this->assertSame(1, $allocator->nextForOrganization(99));
    }

    public function test_tombstone_for_superseded_sale_uses_reserved_range(): void
    {
        $allocator = app(OrderNumberAllocator::class);

        $this->assertSame(
            OrderNumberAllocator::SUPERSEDED_ORDER_NUM_BASE + 42,
            $allocator->tombstoneForSupersededSale(42),
        );
    }
}
