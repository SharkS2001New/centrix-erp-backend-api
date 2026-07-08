<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ManualStockReceiveTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_manual_receive_allows_multiple_products_on_one_receipt_ref(): void
    {
        $products = Product::query()->limit(2)->get();
        $this->assertGreaterThanOrEqual(2, $products->count(), 'Need at least two products in demo data');

        $receiptRef = 'GRN-MULTI-TEST-001';

        foreach ($products as $index => $product) {
            $this->postJson('/api/v1/inventory/receive', [
                'product_code' => $product->product_code,
                'branch_id' => $this->user->branch_id,
                'units_received' => 5 + $index,
                'cost_price' => 100,
                'stock_location' => 'store',
                'invoice_number' => $receiptRef,
            ])->assertCreated();
        }

        $rows = StockReceipt::query()
            ->where('organization_id', $this->user->organization_id)
            ->where('invoice_number', $receiptRef)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            $products->pluck('product_code')->all(),
            $rows->pluck('product_code')->all(),
        );

        foreach ($products as $product) {
            $this->assertSame(
                100.0,
                (float) Product::query()
                    ->where('organization_id', $this->user->organization_id)
                    ->where('product_code', $product->product_code)
                    ->value('last_cost_price'),
            );
        }
    }

    public function test_manual_receive_updates_stock_valuation_cost(): void
    {
        $product = Product::query()->firstOrFail();
        $product->update(['last_cost_price' => 10]);

        $this->postJson('/api/v1/inventory/receive', [
            'product_code' => $product->product_code,
            'branch_id' => $this->user->branch_id,
            'units_received' => 3,
            'cost_price' => 55.5,
            'stock_location' => 'store',
            'invoice_number' => 'GRN-COST-TEST-001',
        ])->assertCreated();

        $this->assertSame(
            55.5,
            (float) $product->fresh()->last_cost_price,
        );

        $valuation = \Illuminate\Support\Facades\DB::table('v_stock_valuation')
            ->where('organization_id', $this->user->organization_id)
            ->where('branch_id', $this->user->branch_id)
            ->where('product_code', $product->product_code)
            ->first();

        $this->assertNotNull($valuation);
        $this->assertSame(55.5, (float) $valuation->last_cost_price);
    }
}
