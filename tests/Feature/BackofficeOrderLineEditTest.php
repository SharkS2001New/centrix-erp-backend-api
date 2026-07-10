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

    protected function createBackofficeSale(float $qty, float $amount, string $status = 'booked'): Sale
    {
        $product = \App\Models\Product::query()->firstOrFail();

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
            'selling_price' => round($amount / $qty, 4),
            'discount_given' => 0,
            'product_vat' => 0,
            'amount' => $amount,
            'on_wholesale_retail' => 0,
        ]);

        return $sale->fresh('items');
    }
}
