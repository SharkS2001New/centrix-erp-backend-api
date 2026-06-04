<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SalesCartCheckoutStockTest extends TestCase
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

    public function test_create_cart_and_add_line_reserves_stock(): void
    {
        $before = $this->availableShop();

        $cart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->assertCreated()->json();

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 5,
        ])->assertCreated();

        $this->assertDatabaseHas('stock_reservations', [
            'cart_id' => $cart['id'],
            'product_code' => $this->productCode,
            'released_at' => null,
        ]);

        $this->assertEquals($before - 5, $this->availableShop());
    }

    public function test_checkout_completes_sale_and_deducts_ledger(): void
    {
        $before = $this->onHandShop();

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 3,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'payment_method_code' => 'CASH',
        ])->assertCreated()->json();

        $this->assertEquals('completed', $sale['status']);
        $this->assertEquals(1, $sale['stock_balanced']);
        $this->assertDatabaseMissing('cart_lines', ['cart_id' => $cartId]);

        $this->assertDatabaseHas('inventory_transactions', [
            'product_code' => $this->productCode,
            'transaction_type' => 'POS_SALE',
            'reference_type' => 'sale',
        ]);

        $this->assertEquals($before - 3, $this->onHandShop());
    }

    public function test_clear_cart_releases_reservation(): void
    {
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 2,
        ]);

        $availAfterReserve = $this->availableShop();

        $this->deleteJson("/api/v1/sales/carts/{$cartId}/lines")->assertOk();

        $this->assertEquals(0, StockReservation::where('cart_id', $cartId)->whereNull('released_at')->count());
        $this->assertGreaterThan($availAfterReserve, $this->availableShop());
    }

    public function test_inventory_availability_endpoint(): void
    {
        $this->getJson('/api/v1/inventory/availability?' . http_build_query([
            'product_code' => $this->productCode,
            'branch_id' => $this->user->branch_id,
            'location' => 'shop',
        ]))
            ->assertOk()
            ->assertJsonStructure(['on_hand', 'reserved', 'available']);
    }

    protected function onHandShop(): float
    {
        return (float) CurrentStock::where('product_code', $this->productCode)
            ->where('branch_id', $this->user->branch_id)
            ->value('shop_quantity');
    }

    protected function availableShop(): float
    {
        $row = CurrentStock::where('product_code', $this->productCode)
            ->where('branch_id', $this->user->branch_id)
            ->first();
        $onHand = (float) ($row->shop_quantity ?? 0);
        $reserved = (float) StockReservation::where('product_code', $this->productCode)
            ->where('branch_id', $this->user->branch_id)
            ->where('stock_location', 'shop')
            ->whereNull('released_at')
            ->sum('quantity');

        return $onHand - $reserved;
    }
}
