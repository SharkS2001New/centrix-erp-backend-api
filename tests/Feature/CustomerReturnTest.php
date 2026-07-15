<?php

namespace Tests\Feature;

use App\Models\CustomerReturn;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CustomerReturnTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_create_and_approve_customer_return_restock(): void
    {
        $product = Product::firstOrFail();
        $sale = Sale::query()->where('status', 'completed')->first();
        if (! $sale) {
            $sale = Sale::query()->firstOrFail();
            $sale->update(['status' => 'completed']);
        }

        SaleItem::query()->updateOrInsert(
            ['sale_id' => $sale->id, 'product_code' => $product->product_code],
            [
                'line_no' => 1,
                'quantity' => 5,
                'selling_price' => 100,
                'amount' => 500,
                'product_vat' => 0,
                'discount_given' => 0,
            ],
        );
        $sale->update(['order_total' => 500, 'amount_paid' => 500, 'payment_status' => 'paid']);

        $created = $this->postJson('/api/v1/customer-returns', [
            'sale_id' => $sale->id,
            'return_date' => '2026-06-11',
            'refund_method' => 'CASH',
            'reason' => 'Damaged Product',
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'product_name' => $product->product_name,
                    'quantity_sold' => 5,
                    'return_qty' => 2,
                    'unit_price' => 100,
                    'amount' => 200,
                ],
            ],
        ])->assertCreated();

        $returnId = $created->json('id');
        $this->assertSame('pending', $created->json('status'));
        $this->assertStringStartsWith('RET-', $created->json('return_no'));

        $this->postJson("/api/v1/customer-returns/{$returnId}/approve")->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('returns', [
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'quantity' => 2,
        ]);

        $this->assertDatabaseHas('inventory_transactions', [
            'product_code' => $product->product_code,
            'transaction_type' => 'RETURN',
            'reference_type' => 'customer_return',
            'reference_id' => $returnId,
        ]);

        $line = \App\Models\CustomerReturnLine::query()
            ->where('customer_return_id', $returnId)
            ->where('product_code', $product->product_code)
            ->first();
        $this->assertNotNull($line?->legacy_return_id);

        $saleItem = SaleItem::query()
            ->where('sale_id', $sale->id)
            ->where('product_code', $product->product_code)
            ->first();
        $this->assertSame(3.0, (float) $saleItem->quantity);
        $this->assertSame(300.0, (float) $saleItem->amount);

        $sale->refresh();
        $this->assertSame(300.0, (float) $sale->order_total);
        $this->assertSame(300.0, (float) $sale->amount_paid);
    }

    public function test_cannot_return_more_than_remaining_quantity(): void
    {
        $product = Product::firstOrFail();
        $sale = Sale::query()->where('status', 'completed')->first() ?? Sale::query()->firstOrFail();
        $sale->update(['status' => 'completed']);

        SaleItem::query()->updateOrInsert(
            ['sale_id' => $sale->id, 'product_code' => $product->product_code],
            [
                'line_no' => 1,
                'quantity' => 3,
                'selling_price' => 100,
                'amount' => 300,
                'product_vat' => 0,
                'discount_given' => 0,
            ],
        );
        $sale->update(['order_total' => 300, 'amount_paid' => 300, 'payment_status' => 'paid']);

        $first = $this->postJson('/api/v1/customer-returns', [
            'sale_id' => $sale->id,
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'quantity_sold' => 3,
                    'return_qty' => 2,
                    'unit_price' => 100,
                    'amount' => 200,
                ],
            ],
        ])->assertCreated();

        $this->postJson("/api/v1/customer-returns/{$first->json('id')}/approve")->assertOk();

        $this->postJson('/api/v1/customer-returns', [
            'sale_id' => $sale->id,
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'quantity_sold' => 3,
                    'return_qty' => 2,
                    'unit_price' => 100,
                    'amount' => 200,
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_reject_pending_return(): void
    {
        $product = Product::query()->skip(1)->first() ?? Product::firstOrFail();
        $sale = Sale::query()->firstOrFail();
        $sale->update(['status' => 'completed']);

        SaleItem::query()->updateOrInsert(
            ['sale_id' => $sale->id, 'product_code' => $product->product_code],
            [
                'line_no' => 99,
                'quantity' => 1,
                'selling_price' => 50,
                'amount' => 50,
                'product_vat' => 0,
                'discount_given' => 0,
            ],
        );

        $created = $this->postJson('/api/v1/customer-returns', [
            'sale_id' => $sale->id,
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'quantity_sold' => 1,
                    'return_qty' => 1,
                    'unit_price' => 50,
                    'amount' => 50,
                ],
            ],
        ])->assertCreated();

        $returnId = $created->json('id');

        $this->postJson("/api/v1/customer-returns/{$returnId}/reject", [
            'reason' => 'Not eligible',
        ])->assertOk()->assertJsonPath('status', 'rejected');
    }

    public function test_returns_report_uses_friendly_detail_columns(): void
    {
        $product = Product::firstOrFail();
        $sale = Sale::query()->where('status', 'completed')->first() ?? Sale::query()->firstOrFail();
        $sale->update(['status' => 'completed']);

        SaleItem::query()->updateOrInsert(
            ['sale_id' => $sale->id, 'product_code' => $product->product_code],
            [
                'line_no' => 1,
                'quantity' => 2,
                'selling_price' => 100,
                'amount' => 200,
                'product_vat' => 0,
                'discount_given' => 0,
            ],
        );
        $sale->update(['order_total' => 200, 'amount_paid' => 200, 'payment_status' => 'paid']);

        $created = $this->postJson('/api/v1/customer-returns', [
            'sale_id' => $sale->id,
            'return_date' => '2026-06-28',
            'reason' => 'Damaged Product',
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'product_name' => $product->product_name,
                    'quantity_sold' => 2,
                    'return_qty' => 1,
                    'unit_price' => 100,
                    'amount' => 100,
                ],
            ],
        ])->assertCreated();

        $this->postJson("/api/v1/customer-returns/{$created->json('id')}/approve")->assertOk();

        $response = $this->getJson('/api/v1/reports/returns?from_date=2026-06-28&to_date=2026-06-28&date_column=return_date&per_page=10')
            ->assertOk();

        $row = collect($response->json('data'))
            ->first(fn ($item) => ($item['product_code'] ?? null) === $product->product_code);

        $this->assertNotNull($row, 'Expected approved return line in report');
        $this->assertArrayHasKey('return_date', $row);
        $this->assertArrayHasKey('customer_name', $row);
        $this->assertArrayHasKey('product_name', $row);
        $this->assertArrayHasKey('quantity', $row);
        $this->assertArrayHasKey('uom_name', $row);
        $this->assertArrayHasKey('returned_by', $row);
        $this->assertArrayNotHasKey('sale_id', $row);
        $this->assertArrayNotHasKey('return_type', $row);
        $this->assertSame($this->user->username, $row['returned_by']);
    }

    public function test_return_lines_use_sold_display_unit_price_not_base_piece_price(): void
    {
        $product = Product::query()->with('unit')->firstOrFail();
        $sale = Sale::query()->create([
            'order_num' => 994168,
            'branch_id' => $this->user->branch_id,
            'organization_id' => $this->user->organization_id,
            'channel' => 'backend',
            'cashier_id' => $this->user->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 9600,
            'total_vat' => 0,
            'amount_paid' => 9600,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Base qty 960 pieces, piece price 10, carton sold at 120.
        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'line_no' => 1,
            'item_code' => '1',
            'quantity' => 960,
            'uom' => 'CARTON',
            'selling_price' => 10,
            'display_unit_price' => 120,
            'discount_given' => 0,
            'product_vat' => 0,
            'amount' => 9600,
            'on_wholesale_retail' => 0,
        ]);

        $sale->load(['items.product.unit']);
        $lines = app(\App\Services\Sales\CustomerReturnService::class)->linesFromSale($sale);
        $line = collect($lines)->firstWhere('product_code', $product->product_code);

        $this->assertNotNull($line);
        $this->assertEquals(120.0, (float) $line['unit_price']);
        $this->assertNotEquals(10.0, (float) $line['unit_price']);
    }
}
