<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
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
}
