<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Catalog\ProductCatalogScopeService;
use App\Services\Inventory\BranchStockService;
use App\Support\SqlLikeSearch;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class StockAccuracyTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_catalog_summary_counts_match_legacy_stock_status_filters(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);
        $branchId = (int) $admin->branch_id;
        $service = app(BranchStockService::class);

        $baseQuery = Product::query()->whereNull('deleted_at');
        app(ProductCatalogScopeService::class)->scopeForUser($baseQuery, $admin, request());

        $aggregated = $service->catalogStockStatusCounts(clone $baseQuery, $branchId);

        $total = (clone $baseQuery)->count();

        $outQuery = clone $baseQuery;
        $service->applyStockStatusFilter($outQuery, 'out_of_stock', $branchId);
        $outOfStock = $outQuery->count();

        $lowQuery = clone $baseQuery;
        $service->applyStockStatusFilter($lowQuery, 'low_stock', $branchId);
        $lowStock = $lowQuery->count();

        $this->assertSame($total, $aggregated['total']);
        $this->assertSame($lowStock, $aggregated['low_stock']);
        $this->assertSame($outOfStock, $aggregated['out_of_stock']);

        $response = $this->getJson('/api/v1/products/catalog-summary?branch_id='.$branchId)
            ->assertOk();

        $this->assertSame($aggregated['total'], $response->json('total'));
        $this->assertSame($aggregated['low_stock'], $response->json('low_stock'));
        $this->assertSame($aggregated['out_of_stock'], $response->json('out_of_stock'));
    }

    public function test_overlay_collection_matches_overlay_payload_with_active_reservations(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $branchId = (int) $admin->branch_id;
        $service = app(BranchStockService::class);

        $product = Product::query()->whereNull('deleted_at')->firstOrFail();

        CurrentStock::query()->updateOrCreate(
            ['branch_id' => $branchId, 'product_code' => $product->product_code],
            ['shop_quantity' => 30, 'store_quantity' => 50],
        );

        StockReservation::query()->create([
            'branch_id' => $branchId,
            'product_code' => $product->product_code,
            'stock_location' => 'shop',
            'quantity' => 5,
            'reserved_by' => $admin->id,
            'released_at' => null,
            'expires_at' => now()->addHour(),
        ]);

        StockReservation::query()->create([
            'branch_id' => $branchId,
            'product_code' => $product->product_code,
            'stock_location' => 'store',
            'quantity' => 12,
            'reserved_by' => $admin->id,
            'expires_at' => now()->addHour(),
        ]);

        $payload = $service->overlayPayload([
            'product_code' => $product->product_code,
        ], $branchId);

        $collection = $service->overlayCollection(collect([
            ['product_code' => $product->product_code],
        ]), $branchId)->first();

        $this->assertSame((float) $payload['stock_in_shop'], (float) $collection['stock_in_shop']);
        $this->assertSame((float) $payload['stock_in_store'], (float) $collection['stock_in_store']);
        $this->assertSame((float) $payload['stock_reserved_shop'], (float) $collection['stock_reserved_shop']);
        $this->assertSame((float) $payload['stock_reserved_store'], (float) $collection['stock_reserved_store']);
        $this->assertSame((float) $payload['stock_available_shop'], (float) $collection['stock_available_shop']);
        $this->assertSame((float) $payload['stock_available_store'], (float) $collection['stock_available_store']);
        $this->assertSame(25.0, (float) $collection['stock_available_shop']);
        $this->assertSame(38.0, (float) $collection['stock_available_store']);
    }

    public function test_expired_and_released_reservations_are_excluded_from_available_stock(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $branchId = (int) $admin->branch_id;
        $service = app(BranchStockService::class);
        $product = Product::query()->whereNull('deleted_at')->firstOrFail();

        CurrentStock::query()->updateOrCreate(
            ['branch_id' => $branchId, 'product_code' => $product->product_code],
            ['shop_quantity' => 20, 'store_quantity' => 0],
        );

        StockReservation::query()->create([
            'branch_id' => $branchId,
            'product_code' => $product->product_code,
            'stock_location' => 'shop',
            'quantity' => 3,
            'reserved_by' => $admin->id,
            'released_at' => null,
            'expires_at' => now()->subMinute(),
        ]);

        StockReservation::query()->create([
            'branch_id' => $branchId,
            'product_code' => $product->product_code,
            'stock_location' => 'shop',
            'quantity' => 4,
            'reserved_by' => $admin->id,
            'released_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        StockReservation::query()->create([
            'branch_id' => $branchId,
            'product_code' => $product->product_code,
            'stock_location' => 'shop',
            'quantity' => 2,
            'reserved_by' => $admin->id,
            'expires_at' => null,
        ]);

        $payload = $service->overlayPayload(['product_code' => $product->product_code], $branchId);

        $this->assertSame(2.0, (float) $payload['stock_reserved_shop']);
        $this->assertSame(18.0, (float) $payload['stock_available_shop']);
    }

    public function test_batch_reservation_map_matches_per_product_queries(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $branchId = (int) $admin->branch_id;

        $products = Product::query()->whereNull('deleted_at')->limit(3)->get();
        $codes = $products->pluck('product_code')->all();

        foreach ($products as $index => $product) {
            CurrentStock::query()->updateOrCreate(
                ['branch_id' => $branchId, 'product_code' => $product->product_code],
                ['shop_quantity' => 10 + $index, 'store_quantity' => 20 + $index],
            );

            StockReservation::query()->create([
                'branch_id' => $branchId,
                'product_code' => $product->product_code,
                'stock_location' => 'shop',
                'quantity' => 1 + $index,
                'reserved_by' => $admin->id,
                'released_at' => null,
                'expires_at' => now()->addHour(),
            ]);
        }

        $service = new class(app(\App\Services\Auth\UserAccessService::class)) extends BranchStockService
        {
            public function map(array $codes, int $branchId): array
            {
                return $this->activeReservedQtyMap($codes, $branchId);
            }

            public function single(string $code, int $branchId, string $location): float
            {
                return $this->activeReservedQty($code, $branchId, $location);
            }
        };

        $map = $service->map($codes, $branchId);

        foreach ($codes as $code) {
            $this->assertSame(
                $service->single($code, $branchId, 'shop'),
                (float) ($map[$code.'|shop'] ?? 0),
            );
            $this->assertSame(
                $service->single($code, $branchId, 'store'),
                (float) ($map[$code.'|store'] ?? 0),
            );
        }
    }

    public function test_product_search_finds_substring_in_product_code(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;

        $product = Product::query()->create([
            'product_code' => 'ZZ-MID-001-END',
            'product_name' => 'Accuracy probe item',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 10,
            'discount_percentage' => 0,
            'organization_id' => $orgId,
            'branch_id' => $admin->branch_id,
            'stock_in_shop' => 1,
            'stock_in_store' => 0,
        ]);

        $query = Product::query()->whereNull('deleted_at')->where('organization_id', $orgId);
        SqlLikeSearch::applyProductSearch($query, 'MID-001');

        $this->assertTrue(
            $query->where('id', $product->id)->exists(),
            'Product code substring search must match middle segments.',
        );
    }

    public function test_customer_search_finds_substring_in_customer_num_within_scoped_query(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $query = Customer::query()->where('organization_id', $admin->organization_id);
        SqlLikeSearch::applyCustomerSearch($query, '12');

        $this->assertNotEmpty(
            $query->limit(5)->pluck('customer_num')->all(),
            'Numeric customer search must match partial customer numbers within the tenant.',
        );
    }
}
