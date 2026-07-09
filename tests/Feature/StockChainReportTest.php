<?php

namespace Tests\Feature;

use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class StockChainReportTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_stock_chain_returns_monetary_totals_and_current_stock(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $product = Product::query()->firstOrFail();
        $branchId = (int) $user->branch_id;

        DB::table('current_stock')->where([
            'product_code' => $product->product_code,
            'branch_id' => $branchId,
        ])->delete();

        InventoryTransaction::query()->create([
            'branch_id' => $branchId,
            'product_code' => $product->product_code,
            'stock_location' => 'store',
            'transaction_type' => 'PURCHASE',
            'reference_type' => 'manual',
            'reference_id' => 1,
            'quantity_change' => 100,
            'quantity_before' => 0,
            'quantity_after' => 100,
            'unit_cost' => 10,
            'created_by' => $user->id,
            'created_at' => now()->subDays(3),
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'organization_id' => $user->organization_id,
            'branch_id' => $branchId,
            'order_num' => 99001,
            'channel' => 'pos',
            'status' => 'completed',
            'order_total' => 50,
            'amount_paid' => 50,
            'total_vat' => 0,
            'cashier_id' => $user->id,
            'archived' => 0,
            'completed_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);

        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'product_code' => $product->product_code,
            'line_no' => 1,
            'quantity' => 1,
            'selling_price' => 50,
            'discount_given' => 0,
            'product_vat' => 0,
            'amount' => 50,
        ]);

        InventoryTransaction::query()->create([
            'branch_id' => $branchId,
            'product_code' => $product->product_code,
            'stock_location' => 'shop',
            'transaction_type' => 'POS_SALE',
            'reference_type' => 'sale',
            'reference_id' => $saleId,
            'quantity_change' => -1,
            'quantity_before' => 1,
            'quantity_after' => 0,
            'unit_cost' => 10,
            'created_by' => $user->id,
            'created_at' => now()->subDay(),
        ]);

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();

        $response = $this->getJson("/api/v1/reports/stock-chain?branch_id={$branchId}&from_date={$from}&to_date={$to}&q={$product->product_code}")
            ->assertOk()
            ->json();

        $row = collect($response['data'] ?? [])
            ->firstWhere('product_code', $product->product_code);

        $this->assertNotNull($row);
        $this->assertSame(1000.0, (float) $row['total_received']);
        $this->assertSame(50.0, (float) $row['total_sold']);
        $this->assertSame(0.0, (float) $row['current_shop_stock']);
        $this->assertSame(100.0, (float) $row['current_store_stock']);
        $this->assertNotNull($row['first_sold_at']);
        $this->assertNotNull($row['last_movement_at']);
    }
}
