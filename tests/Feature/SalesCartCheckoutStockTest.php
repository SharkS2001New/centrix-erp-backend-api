<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Sale;
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
        ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'lines'])
            ->assertJsonCount(1, 'lines');

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

    public function test_update_and_delete_cart_line_by_update_code(): void
    {
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 2,
            'on_wholesale_retail' => 0,
        ])->assertCreated();

        $retailCart = $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
            'on_wholesale_retail' => 1,
        ])->assertCreated()->json();

        $retail = collect($retailCart['lines'] ?? [])->firstWhere('on_wholesale_retail', 1);
        $this->assertNotEmpty($retail['update_code'] ?? null);

        $res = $this->patchJson("/api/v1/sales/carts/{$cartId}/lines/{$retail['update_code']}", [
            'quantity' => 4,
            'on_wholesale_retail' => 1,
        ])->assertOk()->json();

        $lines = $res['lines'] ?? [];
        $updated = collect($lines)->firstWhere('update_code', $retail['update_code']);
        $other = collect($lines)->firstWhere('on_wholesale_retail', 0);

        $this->assertNotNull($updated);
        $this->assertEquals(4.0, (float) ($updated['quantity'] ?? 0));
        $this->assertNotNull($other);
        $this->assertEquals(2.0, (float) ($other['quantity'] ?? 0));

        $this->deleteJson("/api/v1/sales/carts/{$cartId}/lines/{$retail['update_code']}")
            ->assertOk();

        $this->assertDatabaseMissing('cart_lines', ['update_code' => $retail['update_code']]);
    }

    public function test_held_order_can_be_cancelled(): void
    {
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'held',
            'pay_now' => 0,
            'save_only' => true,
        ])->assertCreated()->json();

        $this->postJson("/api/v1/sales/orders/{$sale['id']}/cancel-held")
            ->assertOk();

        $this->assertEquals('cancelled', Sale::find($sale['id'])->status);
    }

    public function test_cart_order_discount_reduces_checkout_total(): void
    {
        $org = \App\Models\Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales']['enable_order_discount'] = true;
        $org->update(['module_settings' => $settings]);

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $lineCart = $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 2,
        ])->assertCreated()->json();

        $lineTotal = (float) (($lineCart['lines'][0]['amount'] ?? 0));

        $this->patchJson("/api/v1/sales/carts/{$cartId}", [
            'order_discount' => 50,
        ])->assertOk()->assertJsonPath('order_discount', 50);

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'payment_method_code' => 'CASH',
        ])->assertCreated()->json();

        $this->assertEquals(50.0, (float) ($sale['order_discount'] ?? 0));
        $this->assertEquals($lineTotal - 50, (float) ($sale['order_total'] ?? 0));
    }

    public function test_hold_order_restore_binds_reservations_to_cart_lines(): void
    {
        $org = $this->user->organization;
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'order_workflow' => array_merge(config('erp.default_order_workflow', []), [
                'steps' => [
                    ['status' => 'booked', 'label' => 'Booked', 'enabled' => true],
                    ['status' => 'pending', 'label' => 'Pending', 'enabled' => true],
                    ['status' => 'unpaid', 'label' => 'Unpaid', 'enabled' => true],
                    ['status' => 'processed', 'label' => 'Processed', 'enabled' => true],
                ],
                'save_status' => ['backend' => 'unpaid'],
                'reserve_stock_on' => ['backend' => 'booked'],
                'deduct_stock_on' => ['backend' => 'processed'],
            ]),
        ]);
        $org->forceFill(['module_settings' => $settings])->save();

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'backend',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 3,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'save_only' => true,
            'pay_now' => 0,
            'payment_method_code' => 'CREDIT',
        ])->assertCreated()->json();

        $this->assertDatabaseHas('stock_reservations', [
            'sale_id' => $sale['id'],
            'product_code' => $this->productCode,
            'released_at' => null,
        ]);

        $cart = $this->postJson("/api/v1/sales/orders/{$sale['id']}/restore-to-cart", [
            'replace' => true,
        ])->assertOk()->json();

        $lineId = (int) ($cart['lines'][0]['id'] ?? 0);
        $this->assertGreaterThan(0, $lineId);

        $this->assertDatabaseHas('stock_reservations', [
            'cart_id' => $cart['id'],
            'cart_line_id' => $lineId,
            'product_code' => $this->productCode,
            'released_at' => null,
        ]);

        $before = $this->availableStore();

        $this->patchJson("/api/v1/sales/carts/{$cart['id']}/lines/{$lineId}", [
            'quantity' => 2,
        ])->assertOk();

        $this->assertEquals(1, StockReservation::query()
            ->where('cart_id', $cart['id'])
            ->where('product_code', $this->productCode)
            ->whereNull('released_at')
            ->count());
        $this->assertEquals($before + 1, $this->availableStore());
    }

    public function test_hold_order_can_be_restored_to_cart(): void
    {
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 2,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'held',
            'pay_now' => 0,
            'save_only' => true,
            'customer_name_override' => 'Walk-in',
        ])->assertCreated()->json();

        $this->assertEquals('held', $sale['status']);

        $cart = $this->postJson("/api/v1/sales/orders/{$sale['id']}/restore-to-cart", [
            'replace' => true,
        ])->assertOk()->json();

        $this->assertCount(1, $cart['lines'] ?? []);
        $this->assertEquals(2.0, (float) ($cart['lines'][0]['quantity'] ?? 0));
        $this->assertEquals('cancelled', Sale::find($sale['id'])->status);
    }

    public function test_backend_save_at_unpaid_reserves_stock_when_reserve_point_is_booked(): void
    {
        $org = $this->user->organization;
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'order_workflow' => array_merge(config('erp.default_order_workflow', []), [
                'steps' => [
                    ['status' => 'booked', 'label' => 'Booked', 'enabled' => true],
                    ['status' => 'pending', 'label' => 'Pending', 'enabled' => true],
                    ['status' => 'unpaid', 'label' => 'Unpaid', 'enabled' => true],
                    ['status' => 'processed', 'label' => 'Processed', 'enabled' => true],
                ],
                'save_status' => ['backend' => 'unpaid'],
                'reserve_stock_on' => ['backend' => 'booked'],
                'deduct_stock_on' => ['backend' => 'processed'],
            ]),
            'stock_deduct_on' => ['backend' => 'trip_load'],
        ]);
        $org->forceFill(['module_settings' => $settings])->save();

        $before = $this->availableStore();

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'backend',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 4,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'save_only' => true,
            'pay_now' => 0,
            'payment_method_code' => 'CREDIT',
        ])->assertCreated()->json();

        $this->assertSame('unpaid', $sale['status'] ?? null);
        $this->assertDatabaseHas('stock_reservations', [
            'sale_id' => $sale['id'],
            'product_code' => $this->productCode,
            'released_at' => null,
        ]);
        $this->assertEquals($before - 4, $this->availableStore());
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

    protected function availableStore(): float
    {
        $row = CurrentStock::where('product_code', $this->productCode)
            ->where('branch_id', $this->user->branch_id)
            ->first();
        $onHand = (float) ($row->store_quantity ?? 0);
        $reserved = (float) StockReservation::where('product_code', $this->productCode)
            ->where('branch_id', $this->user->branch_id)
            ->where('stock_location', 'store')
            ->whereNull('released_at')
            ->sum('quantity');

        return $onHand - $reserved;
    }
}
