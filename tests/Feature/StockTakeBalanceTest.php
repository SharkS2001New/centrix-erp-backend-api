<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StockTakeLine;
use App\Models\StockTakeSession;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class StockTakeBalanceTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
        $this->product = Product::query()->firstOrFail();
        $this->product->update(['last_cost_price' => 40]);
    }

    public function test_stock_take_completion_sets_on_hand_to_counted_against_live_qty(): void
    {
        CurrentStock::query()->updateOrCreate(
            ['product_code' => $this->product->product_code, 'branch_id' => $this->user->branch_id],
            ['shop_quantity' => 25, 'store_quantity' => 0],
        );

        $session = StockTakeSession::create([
            'branch_id' => $this->user->branch_id,
            'session_code' => 'ST-BAL-'.uniqid(),
            'status' => 'in_progress',
            'stock_location' => 'shop',
            'started_by' => $this->user->id,
        ]);

        // Snapshot was 10, but live is 25 after later movements — complete must leave counted qty.
        StockTakeLine::create([
            'session_id' => $session->id,
            'product_code' => $this->product->product_code,
            'stock_location' => 'shop',
            'system_quantity' => 10,
            'counted_quantity' => 18,
        ]);

        $this->postJson("/api/v1/inventory/stock-take/{$session->id}/complete")->assertOk();

        $stock = CurrentStock::query()
            ->where('product_code', $this->product->product_code)
            ->where('branch_id', $this->user->branch_id)
            ->firstOrFail();

        $this->assertSame(18.0, (float) $stock->shop_quantity);

        $line = StockTakeLine::query()->where('session_id', $session->id)->firstOrFail();
        $this->assertSame(25.0, (float) $line->system_quantity);

        $txn = InventoryTransaction::query()
            ->where('reference_type', 'stock_take_session')
            ->where('reference_id', $session->id)
            ->where('product_code', $this->product->product_code)
            ->firstOrFail();

        $this->assertSame(-7.0, (float) $txn->quantity_change);
        $this->assertSame(40.0, (float) $txn->unit_cost);
    }
}
