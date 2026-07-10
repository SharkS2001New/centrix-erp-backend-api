<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\CurrentStock;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class StockValuationSummaryTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_inventory_valuation_summary_totals_are_consistent(): void
    {
        $response = $this->getJson('/api/v1/reports/inventory-valuation-summary?branch_id='.$this->user->branch_id)
            ->assertOk()
            ->assertJsonStructure(['shop_value', 'store_value', 'value', 'branch_id']);

        $shop = (float) $response->json('shop_value');
        $store = (float) $response->json('store_value');
        $total = (float) $response->json('value');

        $this->assertSame(round($shop + $store, 2), $total);
    }

    public function test_stock_on_hand_includes_available_after_reservations(): void
    {
        $product = Product::query()->firstOrFail();
        $branchId = (int) $this->user->branch_id;

        CurrentStock::query()->updateOrCreate(
            [
                'product_code' => $product->product_code,
                'branch_id' => $branchId,
            ],
            [
                'shop_quantity' => 40,
                'store_quantity' => 100,
            ],
        );

        StockReservation::query()->create([
            'branch_id' => $branchId,
            'product_code' => $product->product_code,
            'stock_location' => 'store',
            'quantity' => 10,
            'reserved_by' => $this->user->id,
            'released_at' => null,
            'expires_at' => now()->addHour(),
        ]);

        $row = $this->getJson(
            '/api/v1/reports/stock-on-hand?branch_id='.$branchId.'&product_code='.$product->product_code,
        )->json('data.0');

        $this->assertNotNull($row);
        $this->assertSame(100.0, (float) $row['store_quantity']);
        $this->assertSame(10.0, (float) $row['reserved_store_quantity']);
        $this->assertSame(90.0, (float) $row['available_store_quantity']);
        $this->assertSame(40.0, (float) $row['available_shop_quantity']);
    }

    public function test_stock_on_hand_includes_effective_unit_cost(): void
    {
        $product = Product::query()->firstOrFail();
        $product->update(['last_cost_price' => 33]);

        $response = $this->getJson(
            '/api/v1/reports/stock-on-hand?branch_id='.$this->user->branch_id.'&product_code='.$product->product_code,
        );

        $row = collect($response->json('data'))->firstWhere('product_code', $product->product_code);
        $this->assertNotNull($row);
        $this->assertSame(33.0, (float) $row['effective_unit_cost']);
    }

    public function test_inventory_valuation_summary_falls_back_to_latest_receipt_cost(): void
    {
        $product = Product::query()->firstOrFail();
        $branchId = (int) $this->user->branch_id;

        $product->update(['last_cost_price' => 0]);

        $this->postJson('/api/v1/inventory/receive', [
            'product_code' => $product->product_code,
            'branch_id' => $branchId,
            'units_received' => 2,
            'cost_price' => 80,
            'stock_location' => 'shop',
            'invoice_number' => 'GRN-VAL-SUMMARY-001',
        ])->assertCreated();

        $product->update(['last_cost_price' => 0]);

        $row = $this->getJson(
            '/api/v1/reports/stock-on-hand?branch_id='.$branchId.'&product_code='.$product->product_code,
        )->json('data.0');

        $this->assertNotNull($row);
        $this->assertSame(80.0, (float) $row['effective_unit_cost']);
    }

    public function test_inventory_valuation_summary_uses_uom_conversion_factor(): void
    {
        $product = Product::query()->with('unit')->firstOrFail();
        $branchId = (int) $this->user->branch_id;

        $product->unit?->update(['conversion_factor' => 24]);
        $product->update(['last_cost_price' => 1200]);

        CurrentStock::query()->updateOrCreate(
            [
                'product_code' => $product->product_code,
                'branch_id' => $branchId,
            ],
            [
                'shop_quantity' => 48,
                'store_quantity' => 0,
            ],
        );

        $response = $this->getJson('/api/v1/reports/inventory-valuation-summary?branch_id='.$branchId)
            ->assertOk();

        $row = $this->getJson(
            '/api/v1/reports/stock-on-hand?branch_id='.$branchId.'&product_code='.$product->product_code,
        )->json('data.0');

        $this->assertNotNull($row);
        $this->assertSame(2400.0, (float) $row['shop_cost_value']);
        $this->assertSame(2400.0, (float) $row['total_cost_value']);
        $this->assertGreaterThanOrEqual(2400.0, (float) $response->json('shop_value'));
    }

    public function test_stock_valuation_report_shows_available_qty_and_on_hand_value(): void
    {
        $product = Product::query()->with('unit')->firstOrFail();
        $branchId = (int) $this->user->branch_id;
        $factor = max(1.0, (float) ($product->unit?->conversion_factor ?? 1));

        $product->update(['last_cost_price' => 100]);

        CurrentStock::query()->updateOrCreate(
            [
                'product_code' => $product->product_code,
                'branch_id' => $branchId,
            ],
            [
                'shop_quantity' => 0,
                'store_quantity' => 207,
            ],
        );

        StockReservation::query()->create([
            'branch_id' => $branchId,
            'product_code' => $product->product_code,
            'stock_location' => 'store',
            'quantity' => 10,
            'reserved_by' => $this->user->id,
            'released_at' => null,
            'expires_at' => now()->addHour(),
        ]);

        $row = collect(
            $this->getJson(
                '/api/v1/reports/stock-valuation?branch_id='.$branchId.'&q='.$product->product_code,
            )->assertOk()->json('data'),
        )->firstWhere('product_code', $product->product_code);

        $this->assertNotNull($row);
        $this->assertSame(197.0, (float) $row['store_quantity']);
        $this->assertSame(197.0, (float) $row['store_qty']);
        $this->assertSame(207.0, (float) $row['store_on_hand']);
        $this->assertEqualsWithDelta((207 / $factor) * 100, (float) $row['cost_value'], 0.01);
        $this->assertEqualsWithDelta((207 / $factor) * 100, (float) $row['stock_value'], 0.01);
    }
}
