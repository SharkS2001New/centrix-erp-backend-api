<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockReservation;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class BackofficeOrderLineEditTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_backoffice_order_line_quantities_can_be_updated(): void
    {
        $sale = $this->createBackofficeSale(3, 150.0);

        $this->patchJson("/api/v1/sales/orders/{$sale->id}/line-quantities", [
            'items' => [
                ['id' => $sale->items->first()->id, 'quantity' => 5],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('order_total', 250.0);

        $item = SaleItem::query()->findOrFail($sale->items->first()->id);
        $this->assertEquals(5.0, (float) $item->quantity);
        $this->assertEquals(250.0, (float) $item->amount);
    }

    public function test_completed_backoffice_order_cannot_be_edited(): void
    {
        $sale = $this->createBackofficeSale(2, 100.0, 'completed');

        $this->patchJson("/api/v1/sales/orders/{$sale->id}/line-quantities", [
            'items' => [
                ['id' => $sale->items->first()->id, 'quantity' => 4],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Orders can only be edited while booked, pending, or editable.',
            ]);
    }

    public function test_paid_backoffice_order_cannot_be_edited(): void
    {
        $sale = $this->createBackofficeSale(2, 100.0, 'paid');

        $this->patchJson("/api/v1/sales/orders/{$sale->id}/line-quantities", [
            'items' => [
                ['id' => $sale->items->first()->id, 'quantity' => 3],
            ],
        ])
            ->assertStatus(422);
    }

    public function test_backoffice_line_edit_returns_stock_when_already_deducted(): void
    {
        $sale = $this->createBackofficeSale(10, 500.0, 'pending');
        $item = $sale->items->first();
        $sale->update(['stock_balanced' => 1]);

        $this->patchJson("/api/v1/sales/orders/{$sale->id}/line-quantities", [
            'items' => [
                ['id' => $item->id, 'quantity' => 8],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('order_total', 400.0);

        $this->assertDatabaseHas('inventory_transactions', [
            'reference_type' => 'sale_line_edit',
            'reference_id' => $sale->id,
            'product_code' => $item->product_code,
            'transaction_type' => 'RETURN',
            'quantity_change' => 2,
        ]);
    }

    public function test_backoffice_line_edit_deducts_more_stock_when_already_deducted(): void
    {
        $sale = $this->createBackofficeSale(5, 250.0, 'pending');
        $item = $sale->items->first();
        $sale->update(['stock_balanced' => 1]);

        $this->patchJson("/api/v1/sales/orders/{$sale->id}/line-quantities", [
            'items' => [
                ['id' => $item->id, 'quantity' => 7],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('order_total', 350.0);

        $this->assertDatabaseHas('inventory_transactions', [
            'reference_type' => 'sale_line_edit',
            'reference_id' => $sale->id,
            'product_code' => $item->product_code,
            'transaction_type' => 'BACKEND_SALE',
            'quantity_change' => -2,
        ]);
    }

    public function test_pending_backoffice_order_can_be_edited(): void
    {
        $sale = $this->createBackofficeSale(2, 100.0, 'pending');

        $this->patchJson("/api/v1/sales/orders/{$sale->id}/line-quantities", [
            'items' => [
                ['id' => $sale->items->first()->id, 'quantity' => 4],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('order_total', 200.0);
    }

    public function test_completed_backoffice_order_exposes_can_edit_lines_false_on_index(): void
    {
        $sale = $this->createBackofficeSale(1, 50.0, 'completed');

        $this->getJson('/api/v1/sales?per_page=50&order_source=backoffice')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $sale->id,
                'can_edit_lines' => false,
            ]);
    }

    public function test_booked_backoffice_order_exposes_can_edit_lines_on_index(): void
    {
        $sale = $this->createBackofficeSale(1, 50.0, 'booked');

        $this->getJson('/api/v1/sales?per_page=50&order_source=backoffice')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $sale->id,
                'can_edit_lines' => true,
            ]);
    }

    public function test_backoffice_order_edit_blocked_when_disabled(): void
    {
        $org = $this->user->organization;
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'enable_backoffice_order_edit' => false,
        ]);
        $org->forceFill(['module_settings' => $settings])->save();

        $sale = $this->createBackofficeSale(1, 50.0);

        $this->patchJson("/api/v1/sales/orders/{$sale->id}/line-quantities", [
            'items' => [
                ['id' => $sale->items->first()->id, 'quantity' => 2],
            ],
        ])->assertStatus(422);
    }

    public function test_pos_order_line_edit_is_rejected_when_external_pos_enabled(): void
    {
        $sale = $this->createBackofficeSale(1, 50.0);
        $sale->update(['order_source' => 'pos', 'channel' => 'pos']);

        $this->patchJson("/api/v1/sales/orders/{$sale->id}/line-quantities", [
            'items' => [
                ['id' => $sale->items->first()->id, 'quantity' => 2],
            ],
        ])->assertStatus(422);
    }

    public function test_pos_channel_order_line_edit_allowed_when_external_pos_disabled(): void
    {
        $org = $this->user->organization;
        $org->enabled_modules = [
            'sales.pos' => false,
        ];
        $org->save();

        $gate = app(\App\Services\Erp\CapabilityGate::class)->forOrganization($org->fresh());
        $this->assertFalse($gate->enabled('sales.pos'));

        $sale = $this->createBackofficeSale(1, 50.0, 'booked');
        $sale->update(['order_source' => 'pos', 'channel' => 'pos']);

        $this->patchJson("/api/v1/sales/orders/{$sale->id}/line-quantities", [
            'items' => [
                ['id' => $sale->items->first()->id, 'quantity' => 2],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('order_total', 100);

        $this->getJson('/api/v1/sales?per_page=50')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $sale->id,
                'can_edit_lines' => true,
            ]);
    }

    public function test_backoffice_line_edit_syncs_sale_reservations_before_stock_deduction(): void
    {
        $org = $this->user->organization;
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'order_workflow' => array_merge(config('erp.default_order_workflow', []), [
                'steps' => [
                    ['status' => 'booked', 'label' => 'Booked', 'enabled' => true],
                    ['status' => 'unpaid', 'label' => 'Unpaid', 'enabled' => true],
                    ['status' => 'processed', 'label' => 'Processed', 'enabled' => true],
                ],
                'reserve_stock_on' => ['backend' => 'booked'],
                'deduct_stock_on' => ['backend' => 'processed'],
            ]),
        ]);
        $org->forceFill(['module_settings' => $settings])->save();

        $sale = $this->createBackofficeSale(3, 150.0, 'booked');
        $item = $sale->items->first();

        StockReservation::create([
            'branch_id' => $sale->branch_id,
            'product_code' => $item->product_code,
            'stock_location' => 'store',
            'quantity' => 3,
            'sale_id' => $sale->id,
            'reserved_by' => $this->user->id,
        ]);

        $this->patchJson("/api/v1/sales/orders/{$sale->id}/line-quantities", [
            'items' => [
                ['id' => $item->id, 'quantity' => 5],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('order_total', 250.0);

        $this->assertDatabaseHas('stock_reservations', [
            'sale_id' => $sale->id,
            'product_code' => $item->product_code,
            'stock_location' => 'store',
            'quantity' => 5,
            'released_at' => null,
        ]);
        $this->assertEquals(1, StockReservation::query()
            ->where('sale_id', $sale->id)
            ->whereNull('released_at')
            ->count());
    }

    public function test_backoffice_line_edit_subtracts_order_discount_from_order_total(): void
    {
        $sale = $this->createBackofficeSale(2, 200.0);
        $sale->update(['order_discount' => 30]);

        $this->patchJson("/api/v1/sales/orders/{$sale->id}/line-quantities", [
            'items' => [
                ['id' => $sale->items->first()->id, 'quantity' => 4],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('order_total', 370.0);
    }

    public function test_backoffice_order_can_add_a_line_item(): void
    {
        $sale = $this->createBackofficeSale(2, 100.0);
        $other = \App\Models\Product::query()
            ->where('organization_id', $this->user->organization_id)
            ->where('product_code', '!=', $sale->items->first()->product_code)
            ->first();
        $this->assertNotNull($other);

        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($this->user);
        $updated = app(\App\Services\Sales\BackofficeOrderLineEditService::class)->updateLineQuantities(
            $sale,
            $this->user,
            [
                ['id' => $sale->items->first()->id, 'quantity' => 2],
                ['product_code' => $other->product_code, 'quantity' => 1],
            ],
            $gate,
        );

        $this->assertSame(2, SaleItem::query()->where('sale_id', $updated->id)->count());
        $this->assertTrue(
            SaleItem::query()
                ->where('sale_id', $updated->id)
                ->where('product_code', $other->product_code)
                ->exists()
        );
    }

    public function test_backoffice_order_can_remove_a_line_item(): void
    {
        $sale = $this->createBackofficeSale(2, 100.0);
        $first = $sale->items->first();
        $other = \App\Models\Product::query()
            ->where('organization_id', $this->user->organization_id)
            ->where('product_code', '!=', $first->product_code)
            ->firstOrFail();

        $second = SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => $other->product_code,
            'line_no' => 2,
            'item_code' => '2',
            'quantity' => 1,
            'uom' => $other->uom,
            'selling_price' => 50,
            'discount_given' => 0,
            'product_vat' => 0,
            'amount' => 50,
            'on_wholesale_retail' => 0,
        ]);
        $sale->update(['order_total' => 150]);

        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($this->user);
        $updated = app(\App\Services\Sales\BackofficeOrderLineEditService::class)->updateLineQuantities(
            $sale->fresh('items'),
            $this->user,
            [
                ['id' => $first->id, 'quantity' => 2],
            ],
            $gate,
            [$second->id],
        );

        $this->assertEquals(100.0, (float) $updated->order_total);
        $this->assertDatabaseMissing('sale_items', ['id' => $second->id]);
        $this->assertSame(1, SaleItem::query()->where('sale_id', $sale->id)->count());
    }

    public function test_backoffice_order_can_replace_last_line_with_another_product(): void
    {
        $sale = $this->createBackofficeSale(2, 100.0);
        $item = $sale->items->first();
        $other = \App\Models\Product::query()
            ->where('organization_id', $this->user->organization_id)
            ->where('product_code', '!=', $item->product_code)
            ->firstOrFail();

        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($this->user);
        $updated = app(\App\Services\Sales\BackofficeOrderLineEditService::class)->updateLineQuantities(
            $sale,
            $this->user,
            [
                ['product_code' => $other->product_code, 'quantity' => 1],
            ],
            $gate,
            [$item->id],
        );

        $this->assertSame(1, SaleItem::query()->where('sale_id', $updated->id)->count());
        $this->assertSame(
            $other->product_code,
            SaleItem::query()->where('sale_id', $updated->id)->value('product_code')
        );
    }

    public function test_backoffice_order_customer_can_be_reassigned(): void
    {
        $sale = $this->createBackofficeSale(2, 100.0, 'booked');
        $from = $this->createCustomer('Wrong Customer');
        $to = $this->createCustomer('Correct Customer');
        $sale->update(['customer_num' => $from->customer_num]);

        $updated = app(\App\Services\Sales\BackofficeOrderLineEditService::class)->updateLineQuantities(
            $sale->fresh('items'),
            $this->user,
            [
                ['id' => $sale->items->first()->id, 'quantity' => 2],
            ],
            app(\App\Services\Erp\ErpContext::class)->gateForUser($this->user),
            [],
            $to->customer_num,
        );

        $this->assertSame($to->customer_num, (int) $updated->customer_num);
        $this->assertSame('Correct Customer', (string) $updated->customer?->customer_name);
        $this->assertSame($to->customer_num, (int) $sale->fresh()->customer_num);
    }

    protected function createCustomer(string $name): \App\Models\Customer
    {
        $max = (int) \App\Models\Customer::query()->max('customer_num');

        return \App\Models\Customer::create([
            'customer_num' => $max + 1,
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'customer_name' => $name,
            'customer_type' => 'regular',
            'phone_number' => '07'.random_int(10000000, 99999999),
            'created_by' => $this->user->id,
        ]);
    }

    protected function createBackofficeSale(float $qty, float $amount, string $status = 'booked'): Sale
    {
        $product = \App\Models\Product::query()->firstOrFail();
        $unitPrice = round($amount / $qty, 4);
        // Keep catalog price aligned with the seeded line so repricing (tiers/markups path)
        // still matches the linear unit × qty expectations in these tests.
        $product->forceFill(['unit_price' => $unitPrice])->save();
        \App\Models\RetailPackageSetting::query()
            ->where('product_code', $product->product_code)
            ->delete();

        $sale = Sale::create([
            'order_num' => (int) (Sale::query()->max('order_num') ?? 0) + 1,
            'branch_id' => $this->user->branch_id,
            'organization_id' => $this->user->organization_id,
            'channel' => 'backend',
            'order_source' => 'backoffice',
            'cashier_id' => $this->user->id,
            'status' => $status,
            'total_vat' => 0,
            'order_total' => $amount,
            'payment_method_code' => 'CASH',
            'is_credit_sale' => 0,
            'stock_balanced' => 0,
            'completed_at' => $status === 'completed' ? now() : null,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'line_no' => 1,
            'item_code' => '1',
            'quantity' => $qty,
            'uom' => $product->uom,
            'selling_price' => $unitPrice,
            'discount_given' => 0,
            'product_vat' => 0,
            'amount' => $amount,
            'on_wholesale_retail' => 0,
        ]);

        return $sale->fresh('items');
    }
}
