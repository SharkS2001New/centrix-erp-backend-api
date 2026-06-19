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
}
