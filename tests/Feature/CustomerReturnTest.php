<?php

namespace Tests\Feature;

use App\Models\CustomerReturn;
use App\Models\Organization;
use App\Models\PlatformSubscription;
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
        $this->ensureActiveSubscription($this->user);
        Sanctum::actingAs($this->user);
    }

    protected function ensureActiveSubscription(User $user): void
    {
        $org = Organization::query()->find($user->organization_id);
        if (! $org) {
            return;
        }

        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $org->id],
            [
                'status' => 'active',
                'seat_count' => 5,
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'is_trial' => false,
            ],
        );
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
            'reason' => 'Damaged Product',
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
            'reason' => 'Damaged Product',
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

    public function test_pack_uom_return_expands_single_carton_qty_when_line_amount_is_multi_pack(): void
    {
        $product = Product::query()->whereNotNull('unit_id')->first() ?? Product::firstOrFail();
        $originalUnitId = $product->unit_id;

        $uom = \App\Models\Uom::query()->create([
            'organization_id' => $product->organization_id,
            'conversion_factor' => 18,
            'full_name' => 'Carton',
            'measure_name' => 'piece',
            'small_packaging_label' => 'piece',
            'uses_small_packaging' => true,
            'uom_type' => 'carton',
            'is_base_unit' => false,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        $product->update(['unit_id' => $uom->id]);
        $product->unsetRelation('unit');
        $product->load('unit');

        $sale = Sale::query()->where('status', 'completed')->first() ?? Sale::query()->firstOrFail();
        $sale->update(['status' => 'completed']);

        // Corrupt/legacy storage: qty looks like one carton in pieces, but money is 10 cartons.
        SaleItem::query()->updateOrInsert(
            ['sale_id' => $sale->id, 'product_code' => $product->product_code],
            [
                'line_no' => 1,
                'quantity' => 18,
                'selling_price' => 50.5556,
                'display_unit_price' => 910,
                'amount' => 9100,
                'product_vat' => 0,
                'discount_given' => 0,
                'on_wholesale_retail' => 0,
                'uom' => 'Carton',
            ],
        );
        $sale->update(['order_total' => 9100, 'amount_paid' => 9100, 'payment_status' => 'paid']);

        try {
            $lines = $this->getJson('/api/v1/sales/'.$sale->id.'/return-lines')
                ->assertOk()
                ->json('lines');

            $line = collect($lines)->firstWhere('product_code', $product->product_code);
            $this->assertNotNull($line);
            $this->assertEqualsWithDelta(180.0, (float) $line['max_return_qty'], 0.01);
            $this->assertEqualsWithDelta(910.0, (float) $line['unit_price'], 0.01);

            $created = $this->postJson('/api/v1/customer-returns', [
                'sale_id' => $sale->id,
                'return_date' => '2026-07-17',
                'refund_method' => 'CASH',
                'reason' => 'Damaged Product',
                'lines' => [
                    [
                        'sale_item_id' => $line['sale_item_id'],
                        'product_code' => $product->product_code,
                        'product_name' => $product->product_name,
                        'quantity_sold' => 180,
                        'return_qty' => 162,
                        'unit_price' => 910,
                        'amount' => 8190,
                    ],
                ],
            ])->assertCreated();

            $this->assertEqualsWithDelta(8190.0, (float) $created->json('total_amount'), 0.01);
        } finally {
            $product->update(['unit_id' => $originalUnitId]);
        }
    }

    public function test_pack_uom_return_rejects_over_qty_for_true_single_carton_line(): void
    {
        $product = Product::query()->whereNotNull('unit_id')->first() ?? Product::firstOrFail();
        $originalUnitId = $product->unit_id;

        $uom = \App\Models\Uom::query()->create([
            'organization_id' => $product->organization_id,
            'conversion_factor' => 18,
            'full_name' => 'Carton',
            'measure_name' => 'piece',
            'small_packaging_label' => 'piece',
            'uses_small_packaging' => true,
            'uom_type' => 'carton',
            'is_base_unit' => false,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        $product->update(['unit_id' => $uom->id]);
        $product->unsetRelation('unit');
        $product->load('unit');

        $sale = Sale::query()->where('status', 'completed')->first() ?? Sale::query()->firstOrFail();
        $sale->update(['status' => 'completed']);

        SaleItem::query()->updateOrInsert(
            ['sale_id' => $sale->id, 'product_code' => $product->product_code],
            [
                'line_no' => 1,
                'quantity' => 18,
                'selling_price' => 50.5556,
                'display_unit_price' => 910,
                'amount' => 910,
                'product_vat' => 0,
                'discount_given' => 0,
                'on_wholesale_retail' => 0,
                'uom' => 'Carton',
            ],
        );
        $sale->update(['order_total' => 910, 'amount_paid' => 910, 'payment_status' => 'paid']);

        try {
            $this->postJson('/api/v1/customer-returns', [
                'sale_id' => $sale->id,
                'reason' => 'Damaged Product',
                'lines' => [
                    [
                        'product_code' => $product->product_code,
                        'quantity_sold' => 18,
                        'return_qty' => 162,
                        'unit_price' => 910,
                        'amount' => 8190,
                    ],
                ],
            ])->assertStatus(422)
                ->assertJsonValidationErrors(['lines']);
        } finally {
            $product->update(['unit_id' => $originalUnitId]);
        }
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
            'reason' => 'Damaged Product',
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
}
