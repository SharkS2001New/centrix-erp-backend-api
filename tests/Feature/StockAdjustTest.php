<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class StockAdjustTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected string $productCode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('username', 'admin')->firstOrFail();
        $this->productCode = Product::first()->product_code;
        Sanctum::actingAs($this->user);
    }

    public function test_positive_adjustment_increases_shop_stock(): void
    {
        $before = $this->onHandShop();

        $this->postJson('/api/v1/inventory/adjust', [
            'branch_id' => $this->user->branch_id,
            'product_code' => $this->productCode,
            'stock_location' => 'shop',
            'quantity_change' => 6,
            'notes' => 'Found extra units on shelf',
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_transactions', [
            'product_code' => $this->productCode,
            'transaction_type' => 'ADJUSTMENT',
            'reference_type' => 'adjustment',
            'quantity_change' => 6,
        ]);

        $this->assertEquals($before + 6, $this->onHandShop());
    }

    public function test_negative_adjustment_decreases_shop_stock(): void
    {
        $before = $this->onHandShop();

        $this->postJson('/api/v1/inventory/adjust', [
            'branch_id' => $this->user->branch_id,
            'product_code' => $this->productCode,
            'stock_location' => 'shop',
            'quantity_change' => -2,
            'notes' => 'Miscounted during intake',
        ])->assertCreated();

        $this->assertEquals($before - 2, $this->onHandShop());
    }

    protected function onHandShop(): float
    {
        return (float) CurrentStock::where('product_code', $this->productCode)
            ->where('branch_id', $this->user->branch_id)
            ->value('shop_quantity');
    }
}
