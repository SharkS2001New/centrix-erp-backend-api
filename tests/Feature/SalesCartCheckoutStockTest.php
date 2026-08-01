<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
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
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);

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
        // Ledger deduct runs afterResponse — response may still show unbalanced,
        // but the sale must be balanced once the request finishes.
        $this->assertEquals(1, (int) Sale::query()->findOrFail($sale['id'])->stock_balanced);
        $this->assertDatabaseMissing('cart_lines', ['cart_id' => $cartId]);

        $this->assertDatabaseHas('inventory_transactions', [
            'product_code' => $this->productCode,
            'transaction_type' => 'POS_SALE',
            'reference_type' => 'sale',
        ]);

        $this->assertEquals($before - 3, $this->onHandShop());
    }

    public function test_checkout_renumbers_duplicate_cart_line_nos(): void
    {
        $second = Product::query()
            ->where('product_code', '!=', $this->productCode)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($second, 'Need a second product for multi-line checkout.');

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();
        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $second->product_code,
            'quantity' => 1,
        ])->assertCreated();
        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();

        // Simulate concurrent POS add race: cart_lines has no unique on line_no.
        \App\Models\CartLine::query()
            ->where('cart_id', $cartId)
            ->update(['line_no' => 3]);

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'payment_method_code' => 'CASH',
        ])->assertCreated()->json();

        $lineNos = \App\Models\SaleItem::query()
            ->where('sale_id', $sale['id'])
            ->orderBy('line_no')
            ->pluck('line_no')
            ->all();
        $this->assertSame([1, 2, 3], $lineNos);
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
        $template = Sale::query()->where('organization_id', $this->user->organization_id)->firstOrFail();
        $heldSale = Sale::create([
            'order_num' => 92010,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'pos',
            'order_source' => 'pos',
            'cashier_id' => $this->user->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'held',
            'total_vat' => 0,
            'order_total' => 500,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        $this->postJson("/api/v1/sales/orders/{$heldSale->id}/cancel-held")
            ->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('id', (int) $heldSale->id);

        $this->assertNull(Sale::find($heldSale->id));
    }

    public function test_held_orders_list_is_scoped_to_current_cashier(): void
    {
        $admin = $this->user;
        $otherCashier = User::where('username', 'cashier')->firstOrFail();

        $template = Sale::query()->where('organization_id', $admin->organization_id)->firstOrFail();
        $heldSale = Sale::create([
            'order_num' => 92001,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'pos',
            'order_source' => 'pos',
            'cashier_id' => $admin->id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'held',
            'total_vat' => 0,
            'order_total' => 1000,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        Sanctum::actingAs($otherCashier);

        $this->getJson('/api/v1/sales?per_page=50&filter[status]=held')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/sales/orders/{$heldSale->id}/cancel-held")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'You can only cancel your own held orders.']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/sales?per_page=50&filter[status]=held')
            ->assertOk()
            ->assertJsonPath('data.0.id', $heldSale->id);
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
        $lineVat = (float) (($lineCart['lines'][0]['product_vat'] ?? 0));
        if ($lineTotal > 0 && $lineVat > 0) {
            $expectedVat = round($lineVat * (($lineTotal - 50) / $lineTotal), 2);
            $this->assertEqualsWithDelta($expectedVat, (float) ($sale['total_vat'] ?? 0), 0.01);
        }
    }

    public function test_hold_order_restore_binds_reservations_to_cart_lines(): void
    {
        $org = $this->user->organization;
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'allow_sell_from_shop' => false,
            'allow_sell_from_store' => true,
            'retail_shop_wholesale_store_stock' => false,
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
        $settings['inventory'] = array_merge($settings['inventory'] ?? [], [
            'default_distribution_sale_location' => 'store',
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

    public function test_held_order_keeps_stock_reserved_until_cancelled(): void
    {
        $before = $this->availableShop();

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 3,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'held',
            'pay_now' => 0,
            'save_only' => true,
        ])->assertCreated()->json();

        $this->assertSame('held', $sale['status']);
        $this->assertDatabaseHas('stock_reservations', [
            'sale_id' => $sale['id'],
            'product_code' => $this->productCode,
            'released_at' => null,
        ]);
        $this->assertEquals($before - 3, $this->availableShop());

        $this->postJson("/api/v1/sales/orders/{$sale['id']}/cancel-held")->assertOk();

        $this->assertEquals(0, StockReservation::query()
            ->where('sale_id', $sale['id'])
            ->whereNull('released_at')
            ->count());
        $this->assertEquals($before, $this->availableShop());
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
        $this->assertNull($cart['held_order_num'] ?? null);
        $this->assertNull($cart['superseded_sale_id'] ?? null);
        $this->assertEquals('cancelled', Sale::find($sale['id'])->status);
        // Parked held sale keeps its order_num (not tombstoned); complete books a new sale.
        $this->assertEquals((int) $sale['order_num'], (int) Sale::find($sale['id'])->order_num);
    }

    public function test_backend_save_at_unpaid_reserves_stock_when_reserve_point_is_booked(): void
    {
        $org = $this->user->organization;
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'allow_sell_from_shop' => false,
            'allow_sell_from_store' => true,
            'retail_shop_wholesale_store_stock' => false,
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
        $settings['inventory'] = array_merge($settings['inventory'] ?? [], [
            'default_distribution_sale_location' => 'store',
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

    public function test_invoice_print_blocked_when_reserved_but_physical_stock_gone(): void
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
            'status' => 'unpaid',
            'is_credit_sale' => true,
            'pay_now' => 0,
            'payment_method_code' => 'CREDIT',
        ])->assertCreated()->json();

        $saleId = (int) $sale['id'];
        $this->assertDatabaseHas('stock_reservations', [
            'sale_id' => $saleId,
            'product_code' => $this->productCode,
            'released_at' => null,
        ]);

        // Simulate stock vanishing after reservation (stock take / adjustment).
        CurrentStock::query()
            ->where('product_code', $this->productCode)
            ->where('branch_id', $this->user->branch_id)
            ->update(['shop_quantity' => 0]);

        $detail = $this->getJson("/api/v1/sales/{$saleId}")->assertOk()->json();
        $this->assertFalse(
            (bool) ($detail['can_print_invoice'] ?? true),
            'Tax invoice print must be blocked when physical stock no longer covers the order',
        );
    }

    public function test_invoice_print_allowed_when_sale_reservation_covers_on_hand(): void
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
            'status' => 'unpaid',
            'is_credit_sale' => true,
            'pay_now' => 0,
            'payment_method_code' => 'CREDIT',
        ])->assertCreated()->json();

        $detail = $this->getJson("/api/v1/sales/{$sale['id']}")->assertOk()->json();
        $this->assertTrue(
            (bool) ($detail['can_print_invoice'] ?? false),
            'Print should remain allowed while this sale’s reservation is covered by on-hand stock',
        );
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

    public function test_cart_line_patch_can_swap_product_code(): void
    {
        $replacement = Product::query()
            ->where('product_code', '!=', $this->productCode)
            ->value('product_code');
        $this->assertNotEmpty($replacement);

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $added = $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 2,
        ])->assertCreated()->json();

        $lineRef = $added['lines'][0]['update_code'] ?? $added['lines'][0]['id'];
        $this->assertNotEmpty($lineRef);

        $updated = $this->patchJson("/api/v1/sales/carts/{$cartId}/lines/{$lineRef}", [
            'product_code' => $replacement,
            'quantity' => 3,
            'update_no' => $added['update_no'] ?? 0,
        ])->assertOk()->json();

        $line = collect($updated['lines'] ?? [])->first();
        $this->assertSame($replacement, $line['product_code'] ?? null);
        $this->assertEquals(3, (float) ($line['quantity'] ?? 0));
    }
}
