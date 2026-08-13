<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Operations\StockTakeOperationsController;
use App\Http\Middleware\EnsureOrganizationLicenseActive;
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
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);
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
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'session_code' => 'ST-BAL-'.uniqid(),
            'status' => 'in_progress',
            'stock_location' => 'shop',
            'started_by' => $this->user->id,
        ]);

        // Snapshot was 10, but live is 25 after later movements — counted line must leave counted qty.
        StockTakeLine::create([
            'session_id' => $session->id,
            'product_code' => $this->product->product_code,
            'stock_location' => 'shop',
            'system_quantity' => 10,
            'counted_quantity' => 18,
            'is_counted' => true,
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

    public function test_uncounted_lines_do_not_rewrite_live_stock_after_sales(): void
    {
        CurrentStock::query()->updateOrCreate(
            ['product_code' => $this->product->product_code, 'branch_id' => $this->user->branch_id],
            ['shop_quantity' => 80, 'store_quantity' => 0],
        );

        $session = StockTakeSession::create([
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'session_code' => 'ST-SKIP-'.uniqid(),
            'status' => 'in_progress',
            'stock_location' => 'shop',
            'started_by' => $this->user->id,
        ]);

        // Prefill equals opening snapshot; never saved as counted. Live moved to 80 via sales.
        StockTakeLine::create([
            'session_id' => $session->id,
            'product_code' => $this->product->product_code,
            'stock_location' => 'shop',
            'system_quantity' => 100,
            'counted_quantity' => 100,
            'is_counted' => false,
        ]);

        $this->postJson("/api/v1/inventory/stock-take/{$session->id}/complete")->assertOk();

        $stock = CurrentStock::query()
            ->where('product_code', $this->product->product_code)
            ->where('branch_id', $this->user->branch_id)
            ->firstOrFail();

        $this->assertSame(80.0, (float) $stock->shop_quantity);

        $this->assertSame(
            0,
            InventoryTransaction::query()
                ->where('reference_type', 'stock_take_session')
                ->where('reference_id', $session->id)
                ->count(),
        );
    }

    public function test_save_counts_marks_line_as_counted(): void
    {
        $session = StockTakeSession::create([
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'session_code' => 'ST-SAVE-'.uniqid(),
            'status' => 'in_progress',
            'stock_location' => 'shop',
            'started_by' => $this->user->id,
        ]);

        $line = StockTakeLine::create([
            'session_id' => $session->id,
            'product_code' => $this->product->product_code,
            'stock_location' => 'shop',
            'system_quantity' => 10,
            'counted_quantity' => 10,
            'is_counted' => false,
        ]);

        $this->postJson("/api/v1/inventory/stock-take/{$session->id}/save-counts", [
            'lines' => [
                ['id' => $line->id, 'counted_quantity' => 12],
            ],
        ])->assertOk();

        $line->refresh();
        $this->assertTrue((bool) $line->is_counted);
        $this->assertSame(12.0, (float) $line->counted_quantity);
    }

    public function test_complete_stock_take_session_helper_skips_uncounted_lines(): void
    {
        CurrentStock::query()->updateOrCreate(
            ['product_code' => $this->product->product_code, 'branch_id' => $this->user->branch_id],
            ['shop_quantity' => 55, 'store_quantity' => 0],
        );

        $session = StockTakeSession::create([
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'session_code' => 'ST-HELPER-'.uniqid(),
            'status' => 'in_progress',
            'stock_location' => 'shop',
            'started_by' => $this->user->id,
        ]);

        StockTakeLine::create([
            'session_id' => $session->id,
            'product_code' => $this->product->product_code,
            'stock_location' => 'shop',
            'system_quantity' => 100,
            'counted_quantity' => 100,
            'is_counted' => false,
        ]);

        app(StockTakeOperationsController::class)
            ->completeStockTakeSession($session, $this->user);

        $stock = CurrentStock::query()
            ->where('product_code', $this->product->product_code)
            ->where('branch_id', $this->user->branch_id)
            ->firstOrFail();

        $this->assertSame(55.0, (float) $stock->shop_quantity);
        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_admin_can_reset_stock_take_stocks_to_zero_before_counting(): void
    {
        CurrentStock::query()->updateOrCreate(
            ['product_code' => $this->product->product_code, 'branch_id' => $this->user->branch_id],
            ['shop_quantity' => 42, 'store_quantity' => 15],
        );

        $session = StockTakeSession::create([
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'session_code' => 'ST-RESET-'.uniqid(),
            'status' => 'in_progress',
            'stock_location' => 'both',
            'started_by' => $this->user->id,
        ]);

        $shopLine = StockTakeLine::create([
            'session_id' => $session->id,
            'product_code' => $this->product->product_code,
            'stock_location' => 'shop',
            'system_quantity' => 42,
            'counted_quantity' => 42,
            'is_counted' => false,
        ]);
        $storeLine = StockTakeLine::create([
            'session_id' => $session->id,
            'product_code' => $this->product->product_code,
            'stock_location' => 'store',
            'system_quantity' => 15,
            'counted_quantity' => 15,
            'is_counted' => false,
        ]);

        $this->postJson("/api/v1/inventory/stock-take/{$session->id}/reset-stocks")
            ->assertOk()
            ->assertJsonPath('lines_updated', 2)
            ->assertJsonPath('ledger_adjustments', 2);

        $stock = CurrentStock::query()
            ->where('product_code', $this->product->product_code)
            ->where('branch_id', $this->user->branch_id)
            ->firstOrFail();

        $this->assertSame(0.0, (float) $stock->shop_quantity);
        $this->assertSame(0.0, (float) $stock->store_quantity);

        $shopLine->refresh();
        $storeLine->refresh();
        $this->assertSame(0.0, (float) $shopLine->system_quantity);
        $this->assertSame(0.0, (float) $shopLine->counted_quantity);
        $this->assertSame(0.0, (float) $storeLine->system_quantity);
        $this->assertSame(0.0, (float) $storeLine->counted_quantity);
    }

    public function test_reset_stock_take_stocks_rejects_saved_counts(): void
    {
        $session = StockTakeSession::create([
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'session_code' => 'ST-RESET-BLOCK-'.uniqid(),
            'status' => 'in_progress',
            'stock_location' => 'shop',
            'started_by' => $this->user->id,
        ]);

        StockTakeLine::create([
            'session_id' => $session->id,
            'product_code' => $this->product->product_code,
            'stock_location' => 'shop',
            'system_quantity' => 10,
            'counted_quantity' => 8,
            'is_counted' => true,
        ]);

        $this->postJson("/api/v1/inventory/stock-take/{$session->id}/reset-stocks")
            ->assertStatus(422);
    }
}
