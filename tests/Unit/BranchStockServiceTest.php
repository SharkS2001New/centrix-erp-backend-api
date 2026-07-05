<?php

namespace Tests\Unit;

use App\Services\Auth\UserAccessService;
use App\Services\Inventory\BranchStockService;
use Tests\TestCase;

class BranchStockServiceTest extends TestCase
{
    public function test_split_sales_consumer_stock_preserves_shop_and_store_available_qty(): void
    {
        $service = app(BranchStockService::class);

        $payload = [
            'product_code' => 'P1',
            'stock_in_shop' => 100,
            'stock_in_store' => 200,
            'stock_available_shop' => 12,
            'stock_available_store' => 71,
        ];

        $result = $service->applySalesConsumerStock($payload, 'store', splitShopStore: true);

        $this->assertTrue($result['sales_stock_split']);
        $this->assertSame(12.0, (float) $result['stock_in_shop']);
        $this->assertSame(71.0, (float) $result['stock_in_store']);
        $this->assertSame(100.0, (float) $result['stock_on_hand_shop']);
        $this->assertSame(200.0, (float) $result['stock_on_hand_store']);
    }

    public function test_single_location_sales_consumer_stock_maps_active_location_to_stock_in_shop(): void
    {
        $service = app(BranchStockService::class);

        $payload = [
            'product_code' => 'P1',
            'stock_in_shop' => 0,
            'stock_in_store' => 71,
            'stock_available_shop' => 0,
            'stock_available_store' => 71,
        ];

        $result = $service->applySalesConsumerStock($payload, 'store', splitShopStore: false);

        $this->assertSame('store', $result['sales_stock_location']);
        $this->assertSame(71.0, (float) $result['stock_in_shop']);
        $this->assertSame(71.0, (float) $result['stock_in_store']);
    }

    public function test_active_reserved_qty_map_returns_empty_for_no_product_codes(): void
    {
        $service = new class(app(UserAccessService::class)) extends BranchStockService
        {
            public function reservedMap(array $codes, int $branchId): array
            {
                return $this->activeReservedQtyMap($codes, $branchId);
            }
        };

        $this->assertSame([], $service->reservedMap([], 1));
    }
}
