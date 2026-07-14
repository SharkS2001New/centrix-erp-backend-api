<?php

namespace Tests\Unit;

use App\Services\Sales\OrderNumberAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderNumberAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_order_number_is_one(): void
    {
        $allocator = app(OrderNumberAllocator::class);

        $this->assertSame(1, $allocator->peekNextForOrganization(99));
        $this->assertSame(1, $allocator->nextForOrganization(99));
    }

    public function test_legacy_imported_order_numbers_are_ignored(): void
    {
        $this->insertSaleRow([
            'order_num' => OrderNumberAllocator::LEGACY_IMPORTED_ORDER_NUM_MIN + 42,
            'organization_id' => 99,
        ]);

        $allocator = app(OrderNumberAllocator::class);

        $this->assertSame(1, $allocator->peekNextForOrganization(99));
        $this->assertSame(1, $allocator->nextForOrganization(99));
    }

    public function test_peek_does_not_allocate_and_matches_next(): void
    {
        $this->insertSaleRow([
            'order_num' => 7,
            'organization_id' => 99,
        ]);

        $allocator = app(OrderNumberAllocator::class);

        $this->assertSame(8, $allocator->peekNextForOrganization(99));
        $this->assertSame(8, $allocator->peekNextForOrganization(99));
        $this->assertSame(8, $allocator->nextForOrganization(99));
    }

    public function test_tombstone_for_superseded_sale_uses_reserved_range(): void
    {
        $allocator = app(OrderNumberAllocator::class);

        $this->assertSame(
            OrderNumberAllocator::SUPERSEDED_ORDER_NUM_BASE + 42,
            $allocator->tombstoneForSupersededSale(42),
        );
    }

    /** @param  array<string, mixed>  $overrides */
    protected function insertSaleRow(array $overrides): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::table('sales')->insert(array_merge([
                'branch_id' => 1,
                'channel' => 'pos',
                'payment_status' => 'paid',
                'amount_paid' => 0,
                'cashier_id' => 1,
                'status' => 'completed',
                'total_vat' => 0,
                'order_total' => 0,
                'stock_balanced' => 1,
            ], $overrides));
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
