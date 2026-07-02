<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Inventory\SaleStockLocationResolver;
use Tests\TestCase;

class SaleStockLocationResolverTest extends TestCase
{
    protected function product(bool $sellOnRetail = true): Product
    {
        return new Product([
            'product_code' => 'P1',
            'sell_on_retail' => $sellOnRetail,
        ]);
    }

    public function test_store_only_routes_all_channels_to_store(): void
    {
        $sales = ['allow_sell_from_shop' => false, 'allow_sell_from_store' => true];
        $inventory = ['default_distribution_sale_location' => 'shop'];

        $this->assertSame(
            'store',
            SaleStockLocationResolver::forLine('mobile', $inventory, $sales, $this->product(), false),
        );
        $this->assertSame(
            'store',
            SaleStockLocationResolver::forCatalogList('mobile', $inventory, $sales),
        );
    }

    public function test_shop_only_routes_all_channels_to_shop(): void
    {
        $sales = ['allow_sell_from_shop' => true, 'allow_sell_from_store' => false];
        $inventory = ['default_distribution_sale_location' => 'store'];

        $this->assertSame(
            'shop',
            SaleStockLocationResolver::forLine('mobile', $inventory, $sales, $this->product(), false),
        );
    }

    public function test_retail_shop_wholesale_store_routes_per_line(): void
    {
        $sales = ['retail_shop_wholesale_store_stock' => true];
        $inventory = [];

        $this->assertSame(
            'shop',
            SaleStockLocationResolver::forLine('backend', $inventory, $sales, $this->product(), true),
        );
        $this->assertSame(
            'store',
            SaleStockLocationResolver::forLine('backend', $inventory, $sales, $this->product(), false),
        );
        $this->assertSame(
            'store',
            SaleStockLocationResolver::forCatalogList('mobile', $inventory, $sales),
        );
    }

    public function test_fallback_uses_distribution_default_for_mobile(): void
    {
        $sales = [];
        $inventory = ['default_distribution_sale_location' => 'store', 'default_pos_sale_location' => 'shop'];

        $this->assertSame('store', SaleStockLocationResolver::forCatalogList('mobile', $inventory, $sales));
        $this->assertSame('shop', SaleStockLocationResolver::forCatalogList('pos', $inventory, $sales));
    }
}
