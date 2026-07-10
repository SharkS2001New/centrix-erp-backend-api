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

        $product = Product::query()->with('unit')->firstOrFail();
        $branchId = (int) $user->branch_id;

        DB::table('current_stock')->updateOrInsert(
            [
                'product_code' => $product->product_code,
                'branch_id' => $branchId,
            ],
            [
                'shop_quantity' => 0,
                'store_quantity' => 100,
            ],
        );

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
        $factor = max(1.0, (float) ($product->unit?->conversion_factor ?? 1));
        $this->assertEqualsWithDelta((100 / $factor) * 10, (float) $row['total_received'], 0.01);
        $this->assertSame(50.0, (float) $row['total_sold']);
        $this->assertSame(0.0, (float) $row['current_shop_stock']);
        $this->assertSame(100.0, (float) $row['current_store_stock']);
        $this->assertArrayHasKey('total_cost_value', $row);
        $this->assertNotNull($row['first_received_at']);
        $this->assertNotNull($row['first_sold_at']);
        $this->assertNotNull($row['last_movement_at']);
    }

    public function test_stock_chain_current_stock_is_available_after_reservations(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $product = Product::query()->with('unit')->firstOrFail();
        $branchId = (int) $user->branch_id;

        DB::table('current_stock')->updateOrInsert(
            [
                'product_code' => $product->product_code,
                'branch_id' => $branchId,
            ],
            [
                'shop_quantity' => 0,
                'store_quantity' => 207,
            ],
        );

        InventoryTransaction::query()->create([
            'branch_id' => $branchId,
            'product_code' => $product->product_code,
            'stock_location' => 'store',
            'transaction_type' => 'PURCHASE',
            'reference_type' => 'manual',
            'reference_id' => 99111,
            'quantity_change' => 207,
            'quantity_before' => 0,
            'quantity_after' => 207,
            'unit_cost' => 10,
            'created_by' => $user->id,
            'created_at' => now()->subDays(2),
        ]);

        DB::table('stock_reservations')->insert([
            'branch_id' => $branchId,
            'product_code' => $product->product_code,
            'stock_location' => 'store',
            'quantity' => 10,
            'reserved_by' => $user->id,
            'released_at' => null,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
        ]);

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();

        $row = collect(
            $this->getJson(
                "/api/v1/reports/stock-chain?branch_id={$branchId}&from_date={$from}&to_date={$to}&q={$product->product_code}",
            )->assertOk()->json('data'),
        )->firstWhere('product_code', $product->product_code);

        $this->assertNotNull($row);
        $this->assertSame(197.0, (float) $row['current_store_stock']);
        $this->assertSame(207.0, (float) $row['current_store_on_hand']);
        $factor = max(1.0, (float) ($product->unit?->conversion_factor ?? 1));
        $unitCost = (float) ($row['effective_unit_cost'] ?? 0);
        $this->assertEqualsWithDelta(
            (207 / $factor) * $unitCost,
            (float) $row['total_cost_value'],
            0.01,
        );
    }

    public function test_stock_chain_shows_first_adjustment_for_opening_stock_only_product(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $branchId = (int) $user->branch_id;
        $productCode = 'CHAIN-ADJ-'.uniqid();

        DB::table('products')->insert([
            'organization_id' => $user->organization_id,
            'subcategory_id' => Product::query()->value('subcategory_id'),
            'product_code' => $productCode,
            'product_name' => 'Chain Adjustment Test',
            'unit_id' => Product::query()->value('unit_id'),
            'unit_price' => 100,
            'last_cost_price' => 50,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adjustedAt = now()->subDays(10);
        InventoryTransaction::query()->create([
            'branch_id' => $branchId,
            'product_code' => $productCode,
            'stock_location' => 'store',
            'transaction_type' => 'ADJUSTMENT',
            'reference_type' => 'opening_balance',
            'reference_id' => 1,
            'quantity_change' => 25,
            'quantity_before' => 0,
            'quantity_after' => 25,
            'unit_cost' => 50,
            'created_by' => $user->id,
            'created_at' => $adjustedAt,
        ]);

        $from = now()->subDays(30)->toDateString();
        $to = now()->toDateString();

        $row = collect(
            $this->getJson("/api/v1/reports/stock-chain?branch_id={$branchId}&from_date={$from}&to_date={$to}&q={$productCode}")
                ->assertOk()
                ->json('data') ?? [],
        )->firstWhere('product_code', $productCode);

        $this->assertNotNull($row);
        $this->assertNull($row['first_received_at']);
        $this->assertNotNull($row['first_adjustment_at']);
        $this->assertNotNull($row['first_entered_at']);
        $this->assertSame(
            $adjustedAt->toDateString(),
            substr((string) $row['first_adjustment_at'], 0, 10),
        );
    }

    public function test_stock_chain_preserves_first_receive_date_when_filtering_later_period(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $product = Product::query()->with('unit')->firstOrFail();
        $branchId = (int) $user->branch_id;
        $firstReceiveAt = now()->subDays(90);

        InventoryTransaction::query()->forceCreate([
            'branch_id' => $branchId,
            'product_code' => $product->product_code,
            'stock_location' => 'store',
            'transaction_type' => 'PURCHASE',
            'reference_type' => 'manual',
            'reference_id' => 2,
            'quantity_change' => 10,
            'quantity_before' => 0,
            'quantity_after' => 10,
            'unit_cost' => 5,
            'created_by' => $user->id,
            'created_at' => $firstReceiveAt,
        ]);

        InventoryTransaction::query()->forceCreate([
            'branch_id' => $branchId,
            'product_code' => $product->product_code,
            'stock_location' => 'store',
            'transaction_type' => 'PURCHASE',
            'reference_type' => 'manual',
            'reference_id' => 3,
            'quantity_change' => 5,
            'quantity_before' => 10,
            'quantity_after' => 15,
            'unit_cost' => 5,
            'created_by' => $user->id,
            'created_at' => now()->subDays(2),
        ]);

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();

        $row = collect(
            $this->getJson("/api/v1/reports/stock-chain?branch_id={$branchId}&from_date={$from}&to_date={$to}&q={$product->product_code}")
                ->assertOk()
                ->json('data') ?? [],
        )->firstWhere('product_code', $product->product_code);

        $this->assertNotNull($row);
        $this->assertSame(
            $firstReceiveAt->toDateString(),
            substr((string) $row['first_received_at'], 0, 10),
        );
        $factor = max(1.0, (float) ($product->unit?->conversion_factor ?? 1));
        // Period filter only includes the recent purchase of 5 base units @ cost 5.
        $this->assertEqualsWithDelta((5 / $factor) * 5, (float) $row['total_received'], 0.01);
    }
}
