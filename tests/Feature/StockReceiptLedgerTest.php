<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class StockReceiptLedgerTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_stock_receipt_crud_store_posts_inventory_ledger(): void
    {
        $product = Product::query()->firstOrFail();
        $before = (float) DB::table('current_stock')
            ->where('product_code', $product->product_code)
            ->where('branch_id', $this->user->branch_id)
            ->value('store_quantity');

        $this->postJson('/api/v1/stock-receipts', [
            'product_code' => $product->product_code,
            'branch_id' => $this->user->branch_id,
            'units_received' => 4,
            'cost_price' => 42,
            'stock_location' => 'store',
            'invoice_number' => 'GRN-CRUD-LEDGER-001',
        ])->assertCreated();

        $after = (float) DB::table('current_stock')
            ->where('product_code', $product->product_code)
            ->where('branch_id', $this->user->branch_id)
            ->value('store_quantity');

        $this->assertSame($before + 4, $after);

        $this->assertDatabaseHas('inventory_transactions', [
            'product_code' => $product->product_code,
            'branch_id' => $this->user->branch_id,
            'reference_type' => 'stock_receipt',
            'transaction_type' => 'PURCHASE',
        ]);

        $this->assertSame(
            1,
            StockReceipt::query()->where('invoice_number', 'GRN-CRUD-LEDGER-001')->count(),
        );
    }
}
