<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ProductOpeningStockTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_create_product_with_opening_stock_posts_ledger_atomically(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $code = 'OPEN-STK-'.uniqid();

        $response = $this->postJson('/api/v1/products', [
            'product_code' => $code,
            'product_name' => 'Opening Stock Widget',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 50,
            'vat_id' => 1,
            'opening_stock' => [
                'branch_id' => $user->branch_id,
                'shop_quantity' => 12,
                'store_quantity' => 8,
            ],
        ])->assertCreated();

        $productId = (int) $response->json('id');

        $this->assertDatabaseHas('inventory_transactions', [
            'product_code' => $code,
            'transaction_type' => 'ADJUSTMENT',
            'reference_type' => 'opening_balance',
            'reference_id' => $productId,
            'stock_location' => 'shop',
            'quantity_change' => 12,
        ]);

        $this->assertDatabaseHas('inventory_transactions', [
            'product_code' => $code,
            'stock_location' => 'store',
            'quantity_change' => 8,
        ]);

        $stock = CurrentStock::where('product_code', $code)
            ->where('branch_id', $user->branch_id)
            ->first();

        $this->assertNotNull($stock);
        $this->assertEquals(12.0, (float) $stock->shop_quantity);
        $this->assertEquals(8.0, (float) $stock->store_quantity);
    }

    public function test_opening_stock_failure_rolls_back_product_create(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $code = 'OPEN-FAIL-'.uniqid();

        $this->postJson('/api/v1/products', [
            'product_code' => $code,
            'product_name' => 'Should Not Persist',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 50,
            'vat_id' => 1,
            'opening_stock' => [
                'branch_id' => 99999,
                'shop_quantity' => 5,
            ],
        ])->assertStatus(422);

        $this->assertNull(Product::withTrashed()->where('product_code', $code)->first());
    }
}
