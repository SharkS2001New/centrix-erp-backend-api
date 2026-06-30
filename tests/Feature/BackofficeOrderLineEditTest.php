<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SaleItem;
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

    public function test_completed_backoffice_order_can_be_edited(): void
    {
        $sale = $this->createBackofficeSale(2, 100.0, 'completed');

        $this->patchJson("/api/v1/sales/orders/{$sale->id}/line-quantities", [
            'items' => [
                ['id' => $sale->items->first()->id, 'quantity' => 4],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('order_total', 200.0);
    }

    public function test_paid_backoffice_pos_channel_order_can_be_edited(): void
    {
        $sale = $this->createBackofficeSale(2, 100.0, 'paid');
        $sale->update(['channel' => 'pos', 'completed_at' => now()]);

        $this->getJson('/api/v1/sales?per_page=5')
            ->assertOk();

        $this->patchJson("/api/v1/sales/orders/{$sale->id}/line-quantities", [
            'items' => [
                ['id' => $sale->items->first()->id, 'quantity' => 3],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('order_total', 150.0);
    }

    public function test_completed_backoffice_order_exposes_can_edit_lines_on_index(): void
    {
        $sale = $this->createBackofficeSale(1, 50.0, 'completed');

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

    public function test_pos_order_line_edit_is_rejected(): void
    {
        $sale = $this->createBackofficeSale(1, 50.0);
        $sale->update(['order_source' => 'pos', 'channel' => 'pos']);

        $this->patchJson("/api/v1/sales/orders/{$sale->id}/line-quantities", [
            'items' => [
                ['id' => $sale->items->first()->id, 'quantity' => 2],
            ],
        ])->assertStatus(422);
    }

    protected function createBackofficeSale(float $qty, float $amount, string $status = 'held'): Sale
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
